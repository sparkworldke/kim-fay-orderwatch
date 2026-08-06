"""Ex-VAT money formulas. Never use document/invoice grand totals (PRD section 5.2, 10.2)."""

from __future__ import annotations

from src.acumatica.parsers import float_val


def resolve_unit_price(line_raw: dict, order_qty: float = 0.0) -> float:
    """Ex-VAT unit price. Never ExtendedPrice/Amount (line/invoice totals)."""
    unit_price = float_val(
        line_raw.get("CuryUnitPrice") or line_raw.get("UnitPrice") or line_raw.get("DiscountedUnitPrice")
    )
    if unit_price > 0:
        return unit_price

    ext = resolve_ext_cost(line_raw)
    if ext > 0 and order_qty > 0:
        return round(ext / order_qty, 4)

    return 0.0


def resolve_ext_cost(line_raw: dict) -> float:
    return float_val(
        line_raw.get("CuryExtPrice")
        or line_raw.get("ExtendedPrice")
        or line_raw.get("ExtPrice")
        or line_raw.get("ExtCost")
        or line_raw.get("Amount")
    )


def line_value(qty: float, unit_price: float) -> float:
    """qty × unit_price (ex-VAT). Zero when either input is non-positive."""
    if qty <= 0 or unit_price <= 0:
        return 0.0
    return round(qty * unit_price, 2)


def format_kes(amount: float) -> str:
    return f"KES {amount:,.2f}"


# -------------------------------------------------------------------------
# VAT Net/VAT/Gross breakdown (new-prd.md section 5.2, FR4).
#
# Acumatica calculates tax at the order/tax-zone level via SOTaxTran, which
# is NOT exposed on this tenant's IpayV2/22.200.001 endpoint (confirmed via
# live probe — 404). Until that entity is published, this is a **reverse
# calculation** off the app's existing ex-VAT unit price assumption and the
# configurable VAT_RATE, not the authoritative Acumatica tax detail.
# Price Basis is therefore always labelled "Assumed VAT Exclusive" rather
# than read from a real TaxCalcMode field.
# -------------------------------------------------------------------------

PRICE_BASIS_LABEL = "VAT Exclusive (assumed, 16% reverse-calc)"


def vat_amount(net_value: float, vat_rate: float) -> float:
    if net_value <= 0:
        return 0.0
    return round(net_value * vat_rate, 2)


def gross_value(net_value: float, vat_rate: float) -> float:
    return round(net_value + vat_amount(net_value, vat_rate), 2)
