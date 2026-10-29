#!/usr/bin/env python3
"""Regenera las capturas del README contra una instancia local.

Requiere Playwright con Chromium en el host (`pip install playwright &&
playwright install chromium`) y la cuenta de demostración sembrada
(`docker compose exec php bin/console app:demo:seed`).

Uso:
    python3 tools/screenshots.py
    python3 tools/screenshots.py --base-url https://suscripta.example --out docs/img
"""

from __future__ import annotations

import argparse
import os

from playwright.sync_api import sync_playwright

PAGES = [
    ("dashboard", "/dashboard", True),
    ("discoveries", "/discoveries", True),
    ("services", "/services", True),
    ("alerts", "/alerts", True),
    ("pricing", "/precios", False),
]


def main() -> int:
    """Inicia sesión en la cuenta de demostración y deja las capturas en `--out`."""
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--base-url", default="https://localhost")
    parser.add_argument("--out", default="docs/img")
    parser.add_argument("--email", default=os.environ.get("DEMO_ACCOUNT_EMAIL", "demo@suscripta.local"))
    parser.add_argument("--password", default=os.environ.get("DEMO_ACCOUNT_PASSWORD", "demo1234"))
    args = parser.parse_args()

    os.makedirs(args.out, exist_ok=True)

    with sync_playwright() as playwright:
        browser = playwright.chromium.launch()
        context = browser.new_context(
            ignore_https_errors=True,
            viewport={"width": 1280, "height": 800},
            device_scale_factor=1,
            locale="es-ES",
        )
        page = context.new_page()

        page.goto(f"{args.base_url}/login", wait_until="networkidle")
        page.fill("#email", args.email)
        page.fill("#password", args.password)
        page.click("button[type=submit]")
        page.wait_for_load_state("networkidle")
        if "/login" in page.url:
            print(f"No se pudo entrar como {args.email}; ¿has sembrado la demo?")
            return 1

        for name, path, authenticated in PAGES:
            if not authenticated:
                context.clear_cookies()
            response = page.goto(f"{args.base_url}{path}", wait_until="networkidle")
            if response is None or response.status != 200:
                print(f"{path}: HTTP {response.status if response else 'sin respuesta'}")
                return 1
            page.wait_for_timeout(700)
            target = os.path.join(args.out, f"{name}.png")
            page.screenshot(path=target)
            print(f"{target} ({os.path.getsize(target) // 1024} KB)")

        browser.close()

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
