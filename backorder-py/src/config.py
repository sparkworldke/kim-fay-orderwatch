"""Load runtime configuration from .env (see credentials.md for source values)."""

from __future__ import annotations

import os
from dataclasses import dataclass
from pathlib import Path

from dotenv import load_dotenv

ROOT_DIR = Path(__file__).resolve().parent.parent
load_dotenv(ROOT_DIR / ".env")


@dataclass(frozen=True)
class Settings:
    base_url: str
    token_url: str
    client_id: str
    client_secret: str
    username: str
    password: str
    endpoint: str
    version: str
    tenant: str
    vat_rate: float
    page_size: int
    data_dir: Path

    @property
    def entity_base(self) -> str:
        return f"{self.base_url}/entity/{self.endpoint}/{self.version}"

    def masked_client_id(self) -> str:
        if not self.client_id:
            return "(not set)"
        tail = self.client_id[-4:]
        return f"****{tail}"


def load_settings() -> Settings:
    data_dir = Path(os.getenv("DATA_DIR", "./data"))
    if not data_dir.is_absolute():
        data_dir = ROOT_DIR / data_dir
    data_dir.mkdir(parents=True, exist_ok=True)

    return Settings(
        base_url=os.getenv("ACUMATICA_BASE_URL", "").rstrip("/"),
        token_url=os.getenv("ACUMATICA_TOKEN_URL", ""),
        client_id=os.getenv("ACUMATICA_CLIENT_ID", ""),
        client_secret=os.getenv("ACUMATICA_CLIENT_SECRET", ""),
        username=os.getenv("ACUMATICA_USERNAME", ""),
        password=os.getenv("ACUMATICA_PASSWORD", ""),
        endpoint=os.getenv("ACUMATICA_ENDPOINT", "IpayV2"),
        version=os.getenv("ACUMATICA_VERSION", "22.200.001"),
        tenant=os.getenv("ACUMATICA_TENANT", ""),
        vat_rate=float(os.getenv("VAT_RATE", "0.16")),
        page_size=min(int(os.getenv("PAGE_SIZE", "100")), 200),
        data_dir=data_dir,
    )
