"""Acumatica Contract REST API client for the IpayV2 endpoint.

Guardrails (see PRD-backorder.md section 10.1):
  - Always $expand=Details, never DocumentDetails (KeyNotFoundException on IpayV2).
  - Never rely solely on Status eq 'Backorder' — callers derive backorders from line qty.
  - No $select on Details until fields are confirmed via $adHocSchema.
"""

from __future__ import annotations

import time
from dataclasses import dataclass, field

import requests

from src.acumatica.auth import AcumaticaAuthError, TokenManager
from src.config import Settings

MAX_PAGES_GUARD = 500
INTER_PAGE_DELAY_SECONDS = 0.35
REQUEST_TIMEOUT_SECONDS = 60

TERMINAL_STATUSES = ("Completed", "Cancelled", "Canceled", "Rejected")


class AcumaticaApiError(RuntimeError):
    pass


@dataclass
class FetchMeta:
    pages_fetched: int = 0
    rows_fetched: int = 0
    failed_pages: list[int] = field(default_factory=list)
    errors: list[str] = field(default_factory=list)
    hit_max_pages_guard: bool = False


class AcumaticaClient:
    def __init__(self, settings: Settings):
        self._settings = settings
        self._tokens = TokenManager(settings)

    # -- low-level request ----------------------------------------------------

    def _request(self, entity: str, params: dict, *, retry_on_401: bool = True) -> list[dict]:
        url = f"{self._settings.entity_base}/{entity}"
        token = self._tokens.get_token()
        response = requests.get(
            url,
            params=params,
            headers={"Authorization": f"Bearer {token}"},
            timeout=REQUEST_TIMEOUT_SECONDS,
        )

        if response.status_code == 401 and retry_on_401:
            self._tokens.invalidate()
            token = self._tokens.get_token(force_refresh=True)
            response = requests.get(
                url,
                params=params,
                headers={"Authorization": f"Bearer {token}"},
                timeout=REQUEST_TIMEOUT_SECONDS,
            )

        if response.status_code != 200:
            raise AcumaticaApiError(
                f"{entity} request failed ({response.status_code}): {response.text[:500]}"
            )

        payload = response.json()
        if isinstance(payload, dict) and "value" in payload:
            return payload["value"]
        if isinstance(payload, list):
            return payload
        return []

    # -- filter helpers ---------------------------------------------------------

    @staticmethod
    def sales_order_type_clause() -> str:
        return "OrderType eq 'SO'"

    @classmethod
    def open_sales_orders_filter(cls) -> str:
        exclusions = " and ".join(f"Status ne '{s}'" for s in TERMINAL_STATUSES)
        return f"{cls.sales_order_type_clause()} and {exclusions}"

    @staticmethod
    def date_range_clause(date_from: str, date_to: str) -> str:
        return (
            f"Date ge datetimeoffset'{date_from}T00:00:00' "
            f"and Date le datetimeoffset'{date_to}T23:59:59'"
        )

    # -- SalesOrder pulls ---------------------------------------------------------

    def fetch_open_sales_orders_page(
        self,
        skip: int,
        date_from: str | None = None,
        date_to: str | None = None,
        only_status: str | None = None,
    ) -> list[dict]:
        filt = self.open_sales_orders_filter()
        if date_from and date_to:
            filt = f"{filt} and {self.date_range_clause(date_from, date_to)}"
        if only_status:
            filt = f"{filt} and Status eq '{only_status}'"
        return self._request(
            "SalesOrder",
            {
                "$top": self._settings.page_size,
                "$skip": skip,
                "$filter": filt,
                "$expand": "Details",
            },
        )

    def fetch_all_sales_orders_for_range(
        self,
        date_from: str | None = None,
        date_to: str | None = None,
        *,
        include_terminal: bool = False,
        only_status: str | None = None,
        on_progress=None,
    ) -> tuple[list[dict], FetchMeta]:
        """Paginated pull of open (or all, when include_terminal) SOs with Details.

        include_terminal=True mirrors the "second pass" historical/fill-rate query
        in PRD section 4.2 — same date filter, no status exclusion.

        only_status narrows to a single header Status (e.g. 'Backorder'), on top
        of whichever base filter applies. PRD section 4.2 guardrail: this is
        intentionally NOT the default — Acumatica's header Status can miss lines
        that are genuinely backordered on orders sitting in other open statuses,
        so the default pull derives backorders from line OpenQty instead. Use
        only_status only when you deliberately want the narrower, faster pull.
        """
        meta = FetchMeta()
        rows: list[dict] = []
        skip = 0

        while meta.pages_fetched < MAX_PAGES_GUARD:
            try:
                if include_terminal:
                    filt = self.sales_order_type_clause()
                    if date_from and date_to:
                        filt = f"{filt} and {self.date_range_clause(date_from, date_to)}"
                    if only_status:
                        filt = f"{filt} and Status eq '{only_status}'"
                    page = self._request(
                        "SalesOrder",
                        {
                            "$top": self._settings.page_size,
                            "$skip": skip,
                            "$filter": filt,
                            "$expand": "Details",
                        },
                    )
                else:
                    page = self.fetch_open_sales_orders_page(skip, date_from, date_to, only_status)
            except (AcumaticaApiError, AcumaticaAuthError) as exc:
                meta.failed_pages.append(meta.pages_fetched)
                meta.errors.append(str(exc))
                break

            meta.pages_fetched += 1
            meta.rows_fetched += len(page)
            rows.extend(page)

            if on_progress:
                on_progress(meta.pages_fetched, meta.rows_fetched)

            if len(page) < self._settings.page_size:
                break

            skip += self._settings.page_size
            time.sleep(INTER_PAGE_DELAY_SECONDS)
        else:
            meta.hit_max_pages_guard = True

        return rows, meta

    def probe_sales_order(self, order_nbr: str) -> list[dict]:
        return self._request(f"SalesOrder/{order_nbr}", {"$expand": "Details"})

    def fetch_ad_hoc_schema(self) -> dict:
        url = f"{self._settings.entity_base}/SalesOrder/$adHocSchema"
        token = self._tokens.get_token()
        response = requests.get(
            url, headers={"Authorization": f"Bearer {token}"}, timeout=REQUEST_TIMEOUT_SECONDS
        )
        if response.status_code != 200:
            raise AcumaticaApiError(f"$adHocSchema failed ({response.status_code})")
        return response.json()

    # -- Customer pulls (for CustomerClass -> KP/CS enrichment) -------------------

    def fetch_all_customers(self, on_progress=None) -> list[dict]:
        rows: list[dict] = []
        skip = 0
        pages = 0

        while pages < MAX_PAGES_GUARD:
            page = self._request(
                "Customer",
                {"$top": self._settings.page_size, "$skip": skip},
            )
            pages += 1
            rows.extend(page)
            if on_progress:
                on_progress(pages, len(rows))
            if len(page) < self._settings.page_size:
                break
            skip += self._settings.page_size
            time.sleep(INTER_PAGE_DELAY_SECONDS)

        return rows

    def connection_status(self) -> tuple[bool, str]:
        try:
            self._tokens.get_token(force_refresh=True)
            return True, "Connected"
        except AcumaticaAuthError as exc:
            return False, str(exc)
        finally:
            # A connectivity check has no reason to hold a concurrent-login
            # slot open — Acumatica's Users (SM201010) cap is easy to exhaust
            # if every client is left logged in.
            self.logout()

    def logout(self) -> None:
        """Release the login slot this client is holding. Call after every
        sync/check — see TokenManager.logout for why this matters."""
        self._tokens.logout()
