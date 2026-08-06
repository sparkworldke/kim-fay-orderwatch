"""OAuth2 password-grant token acquisition for Acumatica, cached in memory with a short TTL."""

from __future__ import annotations

import time
from dataclasses import dataclass

import requests

from src.config import Settings

TOKEN_TTL_SECONDS = 15 * 60  # refresh well before Acumatica's own expiry


@dataclass
class TokenState:
    access_token: str | None = None
    fetched_at: float = 0.0

    def is_valid(self) -> bool:
        return bool(self.access_token) and (time.time() - self.fetched_at) < TOKEN_TTL_SECONDS


class AcumaticaAuthError(RuntimeError):
    pass


class TokenManager:
    """Fetches and caches an Acumatica bearer token; refreshes on demand or 401."""

    def __init__(self, settings: Settings):
        self._settings = settings
        self._state = TokenState()

    def get_token(self, force_refresh: bool = False) -> str:
        if force_refresh or not self._state.is_valid():
            self._state = self._fetch_token()
        return self._state.access_token  # type: ignore[return-value]

    def invalidate(self) -> None:
        self._state = TokenState()

    def logout(self) -> None:
        """Release the Acumatica business-logic session tied to this token.

        Acumatica counts "concurrent API logins" per user (Users SM201010) and
        does not free a slot just because our client-side TOKEN_TTL_SECONDS
        cache expires — the server-side session lingers until its own timeout
        or an explicit logout. Every AcumaticaClient() created without calling
        this leaks a login slot; best-effort and never raises, since a failed
        logout shouldn't block the caller.
        """
        if not self._state.access_token:
            return
        try:
            requests.post(
                f"{self._settings.base_url}/entity/auth/logout",
                headers={"Authorization": f"Bearer {self._state.access_token}"},
                timeout=15,
            )
        except requests.RequestException:
            pass
        finally:
            self._state = TokenState()

    def _fetch_token(self) -> TokenState:
        s = self._settings
        if not (s.token_url and s.client_id and s.client_secret and s.username and s.password):
            raise AcumaticaAuthError(
                "Missing Acumatica credentials — check .env against .env.example / credentials.md."
            )

        try:
            response = requests.post(
                s.token_url,
                data={
                    "grant_type": "password",
                    "client_id": s.client_id,
                    "client_secret": s.client_secret,
                    "username": s.username,
                    "password": s.password,
                    "scope": "api",
                },
                headers={"Content-Type": "application/x-www-form-urlencoded"},
                timeout=30,
            )
        except requests.RequestException as exc:
            raise AcumaticaAuthError(f"Token request failed: {exc}") from exc

        if response.status_code != 200:
            raise AcumaticaAuthError(
                f"Token request returned {response.status_code}: {response.text[:500]}"
            )

        payload = response.json()
        access_token = payload.get("access_token")
        if not access_token:
            raise AcumaticaAuthError("Token response did not include access_token")

        return TokenState(access_token=access_token, fetched_at=time.time())
