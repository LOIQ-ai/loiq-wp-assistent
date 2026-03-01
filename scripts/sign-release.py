#!/usr/bin/env python3
"""
Release signing script for LOIQ WordPress Agent
Generates Ed25519 signatures for secure WordPress plugin updates

Usage:
    python3 sign-release.py <version> <zip_path>

Example:
    python3 sign-release.py 3.1.4 ../public/loiq-wp-agent.zip > ../public/update.json

Requires:
    pip install pynacl
"""

import sys
import os
import json
import hashlib
from datetime import datetime, timezone
from pathlib import Path

try:
    from nacl.signing import SigningKey
    from nacl.encoding import Base64Encoder
except ImportError:
    print("Error: pynacl not installed. Run: pip install pynacl", file=sys.stderr)
    sys.exit(1)

# Configuration
DOWNLOAD_BASE_URL = "https://loiq-wp-agent.vercel.app"
KEY_ID = "key-2026-01"
SIGNING_KEY_PATH = Path(__file__).parent / ".signing-key"

# Changelog per version
CHANGELOG = {
    "3.1.4": """
### 3.1.4 - Security & Quality Hardening
- SECURITY: exec() vervangen door token_get_all() voor PHP validatie
- SECURITY: Ed25519 signed auto-updates via Vercel (vervangt unsigned GitHub releases)
- QUALITY: PHP 7.4+ type declarations op alle functies
- i18n: User-facing strings gewrapped in __()/_e()
""",
    "3.1.3": """
### 3.1.3 - Security Fix
- SECURITY: exec() removed from snippet security scanner
- Plugin URI header added
""",
    "3.1.2": """
### 3.1.2 - Bug Fixes
- Various bug fixes and improvements
""",
    "3.0.0": """
### 3.0.0 - Site Builder Endpoints
- NEW: Divi Builder endpoints (build, parse, validate, modules)
- NEW: Theme Builder endpoints (list, create, update, assign)
- NEW: Page endpoints (create, clone, list)
- NEW: Menu endpoints (create, items/add, assign, mega-menu)
- NEW: Media endpoints (upload, search)
- NEW: Forms endpoints (create, update, embed)
- NEW: Child Theme endpoints (functions append/remove)
- NEW: FacetWP endpoints (facet create, template)
- NEW: Taxonomy endpoints (list, create-term, assign)
""",
}


def generate_key_pair():
    """Generate a new Ed25519 key pair for first-time setup."""
    signing_key = SigningKey.generate()
    private_key_b64 = signing_key.encode(encoder=Base64Encoder).decode()
    public_key_b64 = signing_key.verify_key.encode(encoder=Base64Encoder).decode()

    print("=== NEW KEY PAIR GENERATED ===", file=sys.stderr)
    print(f"Key ID: {KEY_ID}", file=sys.stderr)
    print(f"", file=sys.stderr)
    print(f"PRIVATE KEY (save to {SIGNING_KEY_PATH}):", file=sys.stderr)
    print(f"{private_key_b64}", file=sys.stderr)
    print(f"", file=sys.stderr)
    print(f"PUBLIC KEY (add to class-updater.php):", file=sys.stderr)
    print(f"'{KEY_ID}' => '{public_key_b64}',", file=sys.stderr)
    print(f"", file=sys.stderr)

    return signing_key


def load_signing_key():
    """Load existing signing key or generate new one."""
    if not SIGNING_KEY_PATH.exists():
        print(f"No signing key found at {SIGNING_KEY_PATH}", file=sys.stderr)
        print("Generating new key pair...", file=sys.stderr)
        return generate_key_pair()

    with open(SIGNING_KEY_PATH, 'r') as f:
        key_b64 = f.read().strip()

    return SigningKey(key_b64.encode(), encoder=Base64Encoder)


def compute_sha256(file_path):
    """Compute SHA-256 hash of a file."""
    sha256 = hashlib.sha256()
    with open(file_path, 'rb') as f:
        for chunk in iter(lambda: f.read(8192), b''):
            sha256.update(chunk)
    return sha256.hexdigest()


def sign_release(version, zip_path):
    """Sign a release and output update.json."""
    zip_path = Path(zip_path)

    if not zip_path.exists():
        print(f"Error: ZIP file not found: {zip_path}", file=sys.stderr)
        sys.exit(1)

    # Load signing key
    signing_key = load_signing_key()
    public_key_b64 = signing_key.verify_key.encode(encoder=Base64Encoder).decode()

    # Compute checksum
    sha256 = compute_sha256(zip_path)

    # Build download URL
    download_url = f"{DOWNLOAD_BASE_URL}/{zip_path.name}"

    # Timestamp
    released_at = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")

    # Build canonical message (must match plugin exactly)
    canonical_message = f"version={version}\ndownload_url={download_url}\nsha256={sha256}\nreleased_at={released_at}\n"

    # Sign
    signature = signing_key.sign(canonical_message.encode(), encoder=Base64Encoder).signature.decode()

    # Get changelog
    changelog = CHANGELOG.get(version, f"### {version}\n- Bug fixes and improvements")

    # Build update.json
    update_info = {
        "name": "LOIQ WordPress Agent",
        "version": version,
        "download_url": download_url,
        "homepage": "https://loiq.nl",
        "sha256": sha256,
        "released_at": released_at,
        "key_id": KEY_ID,
        "signature": signature,
        "changelog": changelog.strip(),
        "requires": "5.8",
        "requires_php": "7.4",
        "tested": "6.7",
    }

    # Output JSON
    print(json.dumps(update_info, indent=2))

    # Also print verification info to stderr
    print(f"\n=== RELEASE SIGNED ===", file=sys.stderr)
    print(f"Version: {version}", file=sys.stderr)
    print(f"SHA-256: {sha256}", file=sys.stderr)
    print(f"Key ID: {KEY_ID}", file=sys.stderr)
    print(f"Public Key: {public_key_b64}", file=sys.stderr)
    print(f"Released: {released_at}", file=sys.stderr)


def main():
    if len(sys.argv) < 3:
        print("Usage: python3 sign-release.py <version> <zip_path>", file=sys.stderr)
        print("Example: python3 sign-release.py 3.1.4 ../public/loiq-wp-agent.zip", file=sys.stderr)
        sys.exit(1)

    version = sys.argv[1]
    zip_path = sys.argv[2]

    sign_release(version, zip_path)


if __name__ == "__main__":
    main()
