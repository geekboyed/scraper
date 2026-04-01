# codePal Exchange Report

**Task:** check for bugs and security problems
**Generated:** 2026-03-31T12:25:03
**Models queried:** Claude (claude-sonnet-4-6), DeepSeek (deepseek-chat)

---

## Unified Plan

# Unified Security Audit Implementation Plan

## Overview

This plan delivers a comprehensive, multi-layered security and bug analysis framework. It combines static analysis, dynamic testing, dependency scanning, and runtime detection into a single orchestrated system.

> **Note:** Before running this framework, ensure you have code/URLs to analyze. The framework accepts file paths, project directories, or web endpoints as input.

---

## Step 1: Core Data Structures and Severity Model

```python
# core/models.py
from dataclasses import dataclass, field
from enum import Enum
from typing import Optional

class Severity(Enum):
    CRITICAL = 1
    HIGH = 2
    MEDIUM = 3
    LOW = 4
    INFO = 5

    def __str__(self):
        return self.name

@dataclass
class Vulnerability:
    file_path: str
    line_number: int
    vulnerability_type: str
    severity: Severity
    description: str
    recommendation: str
    code_snippet: str
    cve_id: Optional[str] = None
    false_positive_likelihood: float = 0.0  # 0.0–1.0

    def to_dict(self) -> dict:
        return {
            "file_path": self.file_path,
            "line_number": self.line_number,
            "type": self.vulnerability_type,
            "severity": str(self.severity),
            "description": self.description,
            "recommendation": self.recommendation,
            "snippet": self.code_snippet,
            "cve_id": self.cve_id,
        }
```

---

## Step 2: Static Analysis Engine

Regex-based pattern matching covers languages agnostically; Python AST analysis adds deeper structural insight for `.py` files.

```python
# core/static_analyzer.py
import ast
import re
from pathlib import Path
from typing import List, Dict, Any
from .models import Severity, Vulnerability

SECURITY_RULES: Dict[str, Dict[str, Any]] = {
    "sql_injection": {
        "patterns": [
            r'execute\s*\(\s*["\'].*["\']?\s*\+',      # string concat in execute
            r'cursor\.execute\s*\(f["\']',               # f-string in execute
            r'executemany\s*\(\s*["\'].*["\']?\s*\+',
        ],
        "severity": Severity.CRITICAL,
        "description": "Potential SQL injection: user input concatenated into query.",
        "recommendation": "Use parameterized queries or an ORM (e.g., SQLAlchemy).",
    },
    "xss": {
        "patterns": [
            r'\.innerHTML\s*=',
            r'\.outerHTML\s*=',
            r'document\.write\s*\(',
            r'\beval\s*\(',
        ],
        "severity": Severity.HIGH,
        "description": "Potential XSS: unescaped content written to DOM.",
        "recommendation": "Use textContent or a sanitization library (DOMPurify).",
    },
    "hardcoded_secrets": {
        "patterns": [
            r'(?i)(password|passwd|pwd)\s*=\s*["\'][^"\']{8,}["\']',
            r'(?i)(api_key|apikey|api_secret)\s*=\s*["\'][^"\']{10,}["\']',
            r'(?i)(secret|token|auth)\s*=\s*["\'][^"\']{8,}["\']',
            r'(?i)-----BEGIN (RSA|EC|DSA|OPENSSH) PRIVATE KEY-----',
        ],
        "severity": Severity.HIGH,
        "description": "Hardcoded credential or secret detected.",
        "recommendation": "Store secrets in environment variables or a vault (HashiCorp, AWS Secrets Manager).",
    },
    "insecure_deserialization": {
        "patterns": [
            r'\bpickle\.loads?\s*\(',
            r'\byaml\.load\s*\([^,)]*\)',   # yaml.load without Loader=
            r'\bmarshal\.loads?\s*\(',
        ],
        "severity": Severity.CRITICAL,
        "description": "Insecure deserialization can lead to remote code execution.",
        "recommendation": "Use yaml.safe_load(); avoid pickle/marshal on untrusted data.",
    },
    "path_traversal": {
        "patterns": [
            r'\.\./|\.\.\\',
            r'open\s*\(.*\+.*\)',           # dynamic open() calls
        ],
        "severity": Severity.HIGH,
        "description": "Potential path traversal allowing access to arbitrary files.",
        "recommendation": "Validate and canonicalize paths; use os.path.realpath() with allowlists.",
    },
    "command_injection": {
        "patterns": [
            r'\bos\.system\s*\(',
            r'\bsubprocess\.(call|run|Popen)\s*\(.*shell\s*=\s*True',
            r'\beval\s*\(',
            r'\bexec\s*\(',
        ],
        "severity": Severity.CRITICAL,
        "description": "Potential command injection via shell execution.",
        "recommendation": "Avoid shell=True; use subprocess with list arguments and input validation.",
    },
    "insecure_random": {
        "patterns": [
            r'\brandom\.(random|randint|choice|shuffle)\s*\(',
        ],
        "severity": Severity.MEDIUM,
        "description": "Non-cryptographic random used; unsuitable for security contexts.",
        "recommendation": "Use secrets module or os.urandom() for security-sensitive operations.",
    },
    "weak_crypto": {
        "patterns": [
            r'\bhashlib\.(md5|sha1)\s*\(',
            r'\bDES\b|\bRC4\b|\b3DES\b',
        ],
        "severity": Severity.HIGH,
        "description": "Weak or deprecated cryptographic algorithm detected.",
        "recommendation": "Use SHA-256/SHA-3 for hashing; AES-256-GCM for encryption.",
    },
}


class StaticAnalyzer:
    def __init__(self, rules: Dict = None):
        self.rules = rules or SECURITY_RULES
        self.vulnerabilities: List[Vulnerability] = []

    def analyze_file(self, file_path: str, content: str) -> List[Vulnerability]:
        """Regex scan + Python AST scan (if .py)."""
        found = []
        lines = content.splitlines()

        # --- Regex pass (language-agnostic) ---
        for line_num, line in enumerate(lines, start=1):
            for vuln_type, rule in self.rules.items():
                for pattern in rule["patterns"]:
                    if re.search(pattern, line, re.IGNORECASE):
                        vuln = Vulnerability(
                            file_path=file_path,
                            line_number=line_num,
                            vulnerability_type=vuln_type,
                            severity=rule["severity"],
                            description=rule["description"],
                            recommendation=rule["recommendation"],
                            code_snippet=line.strip()[:200],
                        )
                        found.append(vuln)
                        break  # one finding per rule per line

        # --- AST pass (Python only) ---
        if file_path.endswith(".py"):
            found.extend(self._ast_analysis(file_path, content))

        self.vulnerabilities.extend(found)
        return found

    def _ast_analysis(self, file_path: str, content: str) -> List[Vulnerability]:
        """Deeper Python-specific checks via AST."""
        findings = []
        try:
            tree = ast.parse(content)
        except SyntaxError as e:
            # Report the syntax error itself as a bug
            findings.append(Vulnerability(
                file_path=file_path,
                line_number=e.lineno or 0,
                vulnerability_type="syntax_error",
                severity=Severity.HIGH,
                description=f"Syntax error: {e.msg}",
                recommendation="Fix syntax before deployment.",
                code_snippet=str(e.text or "").strip(),
            ))
            return findings

        for node in ast.walk(tree):
            # Detect assert used for security checks (stripped in optimized mode)
            if isinstance(node, ast.Assert):
                findings.append(Vulnerability(
                    file_path=file_path,
                    line_number=node.lineno,
                    vulnerability_type="assert_security_check",
                    severity=Severity.MEDIUM,
                    description="assert statement used for security/validation (disabled with -O flag).",
                    recommendation="Replace with explicit if/raise guards.",
                    code_snippet=ast.unparse(node)[:200],
                ))

            # Detect bare except: clauses that swallow errors
            if isinstance(node, ast.ExceptHandler) and node.type is None:
                findings.append(Vulnerability(
                    file_path=file_path,
                    line_number=node.lineno,
                    vulnerability_type="bare_except",
                    severity=Severity.LOW,
                    description="Bare except: catches all exceptions including SystemExit/KeyboardInterrupt.",
                    recommendation="Catch specific exception types.",
                    code_snippet="except:",
                ))

        return findings

    def analyze_directory(self, directory: str,
                          extensions: tuple = (".py", ".js", ".ts", ".go", ".java")) -> List[Vulnerability]:
        """Recursively analyze all matching files in a directory."""
        all_findings = []
        for path in Path(directory).rglob("*"):
            if path.suffix in extensions and path.is_file():
                try:
                    content = path.read_text(encoding="utf-8", errors="replace")
                    all_findings.extend(self.analyze_file(str(path), content))
                except (OSError, PermissionError) as e:
                    print(f"[WARN] Cannot read {path}: {e}")
        return all_findings
```

---

## Step 3: Dependency Vulnerability Scanner

```python
# core/dependency_checker.py
import re
import requests
from typing import List, Dict, Optional, Tuple
from .models import Severity, Vulnerability


class DependencyChecker:
    OSV_API = "https://api.osv.dev/v1/query"
    REQUEST_TIMEOUT = 10

    def check_requirements_txt(self, req_file: str) -> List[Dict]:
        deps = self._parse_requirements(req_file)
        return self._query_osv_batch(deps, ecosystem="PyPI")

    def check_package_json(self, pkg_file: str) -> List[Dict]:
        import json
        with open(pkg_file) as f:
            data = json.load(f)
        deps = []
        for section in ("dependencies", "devDependencies"):
            for name, version in data.get(section, {}).items():
                clean = re.sub(r"[^0-9.]", "", version)
                deps.append((name, clean or None))
        return self._query_osv_batch(deps, ecosystem="npm")

    def _parse_requirements(self, req_file: str) -> List[Tuple[str, Optional[str]]]:
        deps = []
        with open(req_file) as f:
            for line in f:
                line = line.strip()
                if not line or line.startswith(("#", "-")):
                    continue
                match = re.match(r"([A-Za-z0-9_\-\.]+)(?:[=><~!]+([^\s;]+))?", line)
                if match:
                    pkg = match.group(1)
                    ver = match.group(2) if match.group(2) else None
                    # Strip epoch markers like ==1.0.0 -> 1.0.0
                    if ver:
                        ver = ver.split(",")[0]
                    deps.append((pkg, ver))
        return deps

    def _query_osv_batch(self, deps: List[Tuple], ecosystem: str) -> List[Dict]:
        results = []
        for pkg, version in deps:
            try:
                payload = {"package": {"name": pkg, "ecosystem": ecosystem}}
                if version:
                    payload["version"] = version
                resp = requests.post(self.OSV_API, json=payload, timeout=self.REQUEST_TIMEOUT)
                resp.raise_for_status()
                data = resp.json()
                for vuln in data.get("vulns", []):
                    results.append({
                        "package": pkg,
                        "version": version or "unspecified",
                        "cve": vuln.get("id"),
                        "summary": vuln.get("summary", "No summary"),
                        "severity": self._cvss_to_severity(vuln),
                        "references": [r.get("url") for r in vuln.get("references", [])[:3]],
                    })
            except requests.RequestException as e:
                print(f"[WARN] OSV query failed for {pkg}: {e}")
        return results

    def _cvss_to_severity(self, vuln: Dict) -> str:
        for sev in vuln.get("severity", []):
            if sev.get("type") in ("CVSS_V3", "CVSS_V2"):
                try:
                    score = float(sev["score"])
                    if score >= 9.0:
                        return "CRITICAL"
                    elif score >= 7.0:
                        return "HIGH"
                    elif score >= 4.0:
                        return "MEDIUM"
                    return "LOW"
                except (ValueError, KeyError):
                    pass
        return "UNKNOWN"
```

---

## Step 4: Dynamic Web Security Scanner

> **Safety note:** Only run against systems you own or have explicit written permission to test. Time-based SQL injection probes use a 5-second sleep — adjust `timeout` accordingly.

```python
# core/dynamic_scanner.py
import time
import requests
from urllib.parse import urljoin, urlparse
from typing import List, Dict, Optional


class DynamicScanner:
    SQL_PAYLOADS = [
        ("error_based",  "' OR '1'='1"),
        ("error_based",  "' OR '1'='1' --"),
        ("time_based",   "1' AND SLEEP(5) --"),
        ("union_based",  "1 UNION SELECT NULL,NULL,NULL --"),
    ]
    XSS_PAYLOADS = [
        "<script>alert('XSS')</script>",
        "<img src=x onerror=alert('XSS')>",
        '"><script>alert(1)</script>',
    ]
    SQL_ERROR_INDICATORS = ["sql", "syntax error", "mysql", "postgresql",
                            "ora-", "sqlite", "unclosed quotation"]

    def __init__(self, base_url: str, session: Optional[requests.Session] = None):
        parsed = urlparse(base_url)
        if not parsed.scheme or not parsed.netloc:
            raise ValueError(f"Invalid base URL: {base_url}")
        self.base_url = base_url
        self.session = session or requests.Session()
        self.session.headers.update({"User-Agent": "SecurityAuditBot/1.0"})
        self.findings: List[Dict] = []

    def probe_sql_injection(self, endpoint: str, params: Dict) -> List[Dict]:
        results = []
        for technique, payload in self.SQL_PAYLOADS:
            for key in params:
                test_params = {**params, key: payload}
                try:
                    t0 = time.monotonic()
                    resp = self.session.get(
                        urljoin(self.base_url, endpoint),
                        params=test_params,
                        timeout=12,
                        allow_redirects=False,
                    )
                    elapsed = time.monotonic() - t0

                    triggered = False
                    if technique == "time_based" and elapsed >= 4.5:
                        triggered = True
                    elif technique in ("error_based", "union_based"):
                        body_lower = resp.text.lower()
                        if any(ind in body_lower for ind in self.SQL_ERROR_INDICATORS):
                            triggered = True

                    if triggered:
                        finding = {
                            "type": "SQL Injection",
                            "technique": technique,
                            "endpoint": endpoint,
                            "parameter": key,
                            "payload": payload,
                            "severity": "CRITICAL",
                        }
                        results.append(finding)
                        self.findings.append(finding)

                except requests.Timeout:
                    # A timeout on a time-based payload is also suspicious
                    if technique == "time_based":
                        finding = {
                            "type": "SQL Injection (possible)",
                            "technique": "time_based_timeout",
                            "endpoint": endpoint,
                            "parameter": key,
                            "payload": payload,
                            "severity": "HIGH",
                        }
                        results.append(finding)
                except requests.RequestException as e:
                    print(f"[WARN] Request error on {endpoint}: {e}")
        return results

    def probe_xss(self, endpoint: str, params: Dict) -> List[Dict]:
        results = []
        for payload in self.XSS_PAYLOADS:
            for key in params:
                test_params = {**params, key: payload}
                try:
                    resp = self.session.get(
                        urljoin(self.base_url, endpoint),
                        params=test_params,
                        timeout=10,
                    )
                    if payload in resp.text:
                        finding = {
                            "type": "Reflected XSS",
                            "endpoint": endpoint,
                            "parameter": key,
                            "payload": payload,
                            "severity": "HIGH",
                        }
                        results.append(finding)
                        self.findings.append(finding)
                except requests.RequestException as e:
                    print(f"[WARN] XSS probe error: {e}")
        return results

    def check_security_headers(self, endpoint: str = "/") -> Dict:
        """Verify presence and correctness of security-related HTTP headers."""
        required = {
            "X-Content-Type-Options": lambda v: v.lower() == "nosniff",
            "X-Frame-Options": lambda v: v.upper() in ("DENY", "SAMEORIGIN"),
            "Strict-Transport-Security": lambda v: "max-age=" in v.lower(),
            "Content-Security-Policy": lambda v: bool(v),
            "Referrer-Policy": lambda v: bool(v),
            "X-XSS-Protection": lambda v: v.startswith("1"),
        }
        missing, weak = [], []
        try:
            resp = self.session.get(urljoin(self.base_url, endpoint), timeout=10)
            for header, validator in required.items():
                if header not in resp.headers:
                    missing.append(header)
                elif not validator(resp.headers[header]):
                    weak.append(f"{header}: '{resp.headers[header]}'")
        except requests.RequestException as e:
            return {"error": str(e)}

        return {"missing_headers": missing, "weak_headers": weak}
```

---

## Step 5: Input Validation Utilities

```python
# core/input_validator.py
import re
from typing import Union, List


class InputValidator:
    _VALIDATION_PATTERNS = {
        "email":        r"^[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$",
        "url":          r"^https?://[^\s/$.?#].[^\s]*$",
        "integer":      r"^-?\d+$",
        "float":        r"^-?\d+(?:\.\d+)?$",
        "alphanumeric": r"^[a-zA-Z0-9]+$",
        "uuid":         r"^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$",
        "phone":        r"^\+?[\d\s\-\(\)]{10,20}$",
    }

    def validate(self, value: str, kind: str) -> bool:
        pattern = self._VALIDATION_PATTERNS.get(kind)
        if not pattern:
            raise ValueError(f"Unknown validation type: {kind}")
        return bool(re.fullmatch(pattern, value, re.IGNORECASE))

    def sanitize_html(self, text: str) -> str:
        """Minimal HTML entity encoding — prefer a library like bleach for production."""
        return (text
                .replace("&", "&amp;")
                .replace("<", "&lt;")
                .replace(">", "&gt;")
                .replace('"', "&quot;")
                .replace("'", "&#x27;")
                .replace("\x00", ""))

    def sanitize_path(self, path: str) -> str:
        """Strip traversal sequences and dangerous characters."""
        path = re.sub(r"\.\.[/\\]", "", path)
        path = re.sub(r"[<>:\"|?*\x00]", "", path)
        return path

    def check_for_attacks(self, value: str) -> List[str]:
        """Returns list of detected attack pattern names."""
        attack_patterns = {
            "sql_injection": r"([';]|--\s|/\*|\*/|union\s+select|drop\s+table)",
            "xss":           r"(<\s*script|javascript:|on\w+\s*=)",
            "path_traversal": r"\.\.[/\\]|/etc/passwd|/etc/shadow",
            "command_injection": r"[;&|`$]\s*\w|\$\(",
        }
        detected = []
        for name, pattern in attack_patterns.items():
            if re.search(pattern, value, re.IGNORECASE):
                detected.append(name)
        return detected
```

---

## Step 6: Main Orchestrator with Reporting

```python
# security_audit.py
import json
import sys
from pathlib import Path
from datetime import datetime
from typing import Dict, Any

from core.models import Severity
from core.static_analyzer import StaticAnalyzer
from core.dependency_checker import DependencyChecker
from core.dynamic_scanner import DynamicScanner


class SecurityAudit:
    def __init__(self, project_path: str = None, base_url: str = None):
        self.project_path = Path(project_path) if project_path else None
        self.base_url = base_url
        self.report: Dict[str, Any] = {
            "timestamp": datetime.utcnow().isoformat() + "Z",
            "project_path": project_path,
            "base_url": base_url,
            "static_findings": [],
            "dependency_findings": [],
            "dynamic_findings": [],
            "header_findings": {},
            "summary": {},
        }

    # ------------------------------------------------------------------
    # Static analysis
    # ------------------------------------------------------------------
    def run_static_analysis(self, extensions=(".py", ".js", ".ts", ".java", ".go")):
        if not self.project_path:
            print("[SKIP] No project path provided for static analysis.")
            return
        print(f"[*] Static analysis: {self.project_path}")
        analyzer = StaticAnalyzer()
        findings = analyzer.analyze_directory(str(self.project_path), extensions)
        self.report["static_findings"] = [v.to_dict() for v in findings]
        print(f"    → {len(findings)} finding(s)")

    # ------------------------------------------------------------------
    # Dependency check
    # ------------------------------------------------------------------
    def run_dependency_check(self):
        if not self.project_path:
            return
        checker = DependencyChecker()
        all_dep_findings = []

        req = self.project_path / "requirements.txt"
        if req.exists():
            print(f"[*] Checking {req}")
            try:
                results = checker.check_requirements_txt(str(req))
                all_dep_findings.extend(results)
                print(f"    → {len(results)} vulnerable package(s)")
            except Exception as e:
                print(f"[WARN] Dependency check failed: {e}")

        pkg = self.project_path / "package.json"
        if pkg.exists():
            print(f"[*] Checking {pkg}")
            try:
                results = checker.check_package_json(str(pkg))
                all_dep_findings.extend(results)
                print(f"    → {len(results)} vulnerable package(s)")
            except Exception as e:
                print(f"[WARN] npm dependency check failed: {e}")

        self.report["dependency_findings"] = all_dep_findings

    # ------------------------------------------------------------------
    # Dynamic scan
    # ------------------------------------------------------------------
    def run_dynamic_scan(self, endpoints: list = None):
        if not self.base_url:
            print("[SKIP] No base URL provided for dynamic scanning.")
            return
        print(f"[*] Dynamic scan: {self.base_url}")
        scanner = DynamicScanner(self.base_url)

        # Header check
        self.report["header_findings"] = scanner.check_security_headers()

        # Endpoint probes (provide your own endpoint/param map)
        if endpoints:
            for ep_config in endpoints:
                endpoint = ep_config.get("path", "/")
                params = ep_config.get("params", {})
                scanner.probe_sql_injection(endpoint, params)
                scanner.probe_xss(endpoint, params)

        self.report["dynamic_findings"] = scanner.findings
        print(f"    → {len(scanner.findings)} finding(s)")

    # ------------------------------------------------------------------
    # Reporting
    # ------------------------------------------------------------------
    def _compute_summary(self):
        counts = {s.name: 0 for s in Severity}
        counts["UNKNOWN"] = 0
        total = 0

        def _tally(sev_str: str):
            nonlocal total
            total += 1
            key = str(sev_str).upper()
            if key in counts:
                counts[key] += 1
            else:
                counts["UNKNOWN"] += 1

        for f in self.report["static_findings"]:
            _tally(f.get("severity", "UNKNOWN"))
        for f in self.report["dependency_findings"]:
            _tally(f.get("severity", "UNKNOWN"))
        for f in self.report["dynamic_findings"]:
            _tally(f.get("severity", "UNKNOWN"))

        self.report["summary"] = {"total": total, **counts}

    def save_report(self, output_path: str = "security_report.json"):
        self._compute_summary()
        with open(output_path, "w") as f:
            json.dump(self.report, f, indent=2, default=str)
        self._print_summary()
        print(f"\n[✓] Full report saved to: {output_path}")

    def _print_summary(self):
        s = self.report["summary"]
        print("\n" + "=" * 50)
        print("SECURITY AUDIT SUMMARY")
        print("=" * 50)
        print(f"  Total findings : {s.get('total', 0)}")
        print(f"  🔴 CRITICAL    : {s.get('CRITICAL', 0)}")
        print(f"  🟠 HIGH        : {s.get('HIGH', 0)}")
        print(f"  🟡 MEDIUM      : {s.get('MEDIUM', 0)}")
        print(f"  🟢 LOW         : {s.get('LOW', 0)}")
        print(f"  ℹ️  INFO        : {s.get('INFO', 0)}")
        print("=" * 50)

        headers = self.report.get("header_findings", {})
        if headers.get("missing_headers"):
            print(f"\n  ⚠️  Missing HTTP security headers: {headers['missing_headers']}")
        if headers.get("weak_headers"):
            print(f"  ⚠️  Weak HTTP security headers: {headers['weak_headers']}")


# ------------------------------------------------------------------
# Entry point
# ------------------------------------------------------------------
if __name__ == "__main__":
    # Example usage — customize these values:
    audit = SecurityAudit(
        project_path="./my_project",   # set to None to skip static/dep scans
        base_url="https://example.com" # set to None to skip dynamic scan
    )

    audit.run_static_analysis()
    audit.run_dependency_check()

    # Define which endpoints and parameters to probe dynamically:
    audit.run_dynamic_scan(endpoints=[
        {"path": "/search", "params": {"q": "test", "page": "1"}},
        {"path": "/login",  "params": {"username": "admin", "password": "x"}},
    ])

    audit.save_report("security_report.json")
    sys.exit(0 if audit.report["summary"].get("CRITICAL", 0) == 0 else 1)
```

---

## Step 7: Installation and Usage

```bash
# Install dependencies
pip install requests

# Optional but recommended tools to complement this framework:
pip install bandit          # Python-specific SAST
pip install safety          # Python CVE checker (CLI)
pip install semgrep         # Multi-language SAST rules

# Run bandit alongside this framework:
bandit -r ./my_project -f json -o bandit_report.json

# Run the unified framework:
python security_audit.py
```

---

## Step 8: CI/CD Integration

```yaml
# .github/workflows/security.yml
name: Security Audit
on: [push, pull_request]

jobs:
  audit:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Set up Python
        uses: actions/setup-python@v5
        with:
          python-version: "3.11"

      - name: Install dependencies
        run: pip install requests bandit safety

      - name: Run Bandit (SAST)
        run: bandit -r . -ll -f json -o bandit_report.json || true

      - name: Run Safety (CVE check)
        run: safety check --json > safety_report.json || true

      - name: Run unified security audit
        run: python security_audit.py
        env:
          AUDIT_PROJECT_PATH: "."
          # Do NOT run dynamic scan in CI against production URLs

      - name: Upload reports
        uses: actions/upload-artifact@v4
        with:
          name: security-reports
          path: |
            security_report.json
            bandit_report.json
            safety_report.json
```

---

## Decision Notes

| Decision | Rationale |
|---|---|
| Regex + AST (not regex alone) | AST catches structural issues (bare `except:`, misused `assert`) invisible to regex; regex handles multi-language coverage |
| OSV.dev API for dependencies | Free, authoritative, covers PyPI + npm; no API key required; more up-to-date than static DBs |
| Dynamic scanner is opt-in | Prevents accidental testing of production systems; caller must explicitly supply `base_url` and endpoints |
| `sys.exit(1)` on CRITICAL | Enables CI/CD gates to block merges when critical findings exist |
| No runtime race-condition detector included | The threading-instrumentation approach from the source proposals is too invasive for a general-purpose tool; recommend ThreadSanitizer (Go/C++) or `asyncio` linting for targeted use instead |
| Secrets handled via environment variables | Never accept credentials via CLI args (visible in `ps`/logs); use `os.environ` in production config |

---

## Claude (claude-sonnet-4-6) Proposal

I'd be happy to help check for bugs and security problems, but **you haven't shared any code yet**.

Please paste the code you'd like me to review, and specify:

1. **Language/Framework** (e.g., Python/Django, JavaScript/Node.js, Go, etc.)
2. **What the code does** (brief description)
3. **Any specific concerns** you already have (optional)

---

Once you share it, I'll check for:

### 🐛 Bugs
- Logic errors
- Off-by-one errors
- Null/undefined dereferences
- Race conditions
- Error handling gaps
- Incorrect assumptions

### 🔒 Security Issues
- Injection vulnerabilities (SQL, command, XSS, etc.)
- Authentication/authorization flaws
- Insecure cryptography
- Secrets/credentials in code
- Insecure deserialization
- Path traversal
- CSRF / SSRF
- Dependency vulnerabilities

### ⚡ Other
- Memory leaks
- Performance issues
- Deprecated APIs

**Go ahead and paste your code!**

---

## DeepSeek (deepseek-chat) Proposal

I'll provide a comprehensive approach to checking for bugs and security problems in software. Here's a detailed implementation plan:

## 1. **Static Analysis Framework**

### **Architecture Design**
```python
# core/static_analyzer.py
from typing import List, Dict, Any
import ast
import re
from dataclasses import dataclass
from enum import Enum

class Severity(Enum):
    CRITICAL = 1
    HIGH = 2
    MEDIUM = 3
    LOW = 4
    INFO = 5

@dataclass
class Vulnerability:
    file_path: str
    line_number: int
    vulnerability_type: str
    severity: Severity
    description: str
    recommendation: str
    code_snippet: str

class StaticAnalyzer:
    def __init__(self):
        self.rules = self._load_security_rules()
        self.vulnerabilities: List[Vulnerability] = []
    
    def _load_security_rules(self) -> Dict[str, Dict[str, Any]]:
        return {
            'sql_injection': {
                'patterns': [
                    r'execute\(.*\+.*\)',
                    r'executemany\(.*\+.*\)',
                    r'cursor\.execute\(f".*"\)',
                    r'cursor\.execute\(".*" \+ .*\)'
                ],
                'severity': Severity.CRITICAL,
                'description': 'Potential SQL injection vulnerability',
                'recommendation': 'Use parameterized queries or ORM'
            },
            'xss': {
                'patterns': [
                    r'\.innerHTML\s*=',
                    r'\.outerHTML\s*=',
                    r'document\.write\(',
                    r'eval\('
                ],
                'severity': Severity.HIGH,
                'description': 'Potential Cross-Site Scripting vulnerability',
                'recommendation': 'Use textContent instead of innerHTML or sanitize inputs'
            },
            'hardcoded_secrets': {
                'patterns': [
                    r'password\s*=\s*["\'][^"\']{8,}["\']',
                    r'api_key\s*=\s*["\'][^"\']{10,}["\']',
                    r'secret\s*=\s*["\'][^"\']{8,}["\']',
                    r'token\s*=\s*["\'][^"\']{10,}["\']'
                ],
                'severity': Severity.HIGH,
                'description': 'Hardcoded secret detected',
                'recommendation': 'Use environment variables or secure secret management'
            },
            'insecure_deserialization': {
                'patterns': [
                    r'pickle\.loads\(',
                    r'yaml\.load\(',
                    r'marshal\.loads\('
                ],
                'severity': Severity.CRITICAL,
                'description': 'Insecure deserialization detected',
                'recommendation': 'Use safe serialization formats or validate inputs'
            }
        }
    
    def analyze_file(self, file_path: str, content: str):
        """Analyze a single file for security vulnerabilities"""
        for line_num, line in enumerate(content.split('\n'), 1):
            for vuln_type, rule in self.rules.items():
                for pattern in rule['patterns']:
                    if re.search(pattern, line, re.IGNORECASE):
                        self.vulnerabilities.append(
                            Vulnerability(
                                file_path=file_path,
                                line_number=line_num,
                                vulnerability_type=vuln_type,
                                severity=rule['severity'],
                                description=rule['description'],
                                recommendation=rule['recommendation'],
                                code_snippet=line.strip()
                            )
                        )
```

## 2. **Dynamic Security Testing**

### **Web Application Security Scanner**
```python
# security/scanner.py
import requests
from urllib.parse import urljoin
from concurrent.futures import ThreadPoolExecutor
import time

class SecurityScanner:
    def __init__(self, base_url: str):
        self.base_url = base_url
        self.session = requests.Session()
        self.vulnerabilities = []
        
    def check_sql_injection(self, endpoint: str, params: dict):
        """Test for SQL injection vulnerabilities"""
        payloads = [
            "' OR '1'='1",
            "' OR '1'='1' --",
            "1' AND SLEEP(5) --",
            "1 UNION SELECT NULL, NULL, NULL --"
        ]
        
        for payload in payloads:
            test_params = params.copy()
            for key in test_params:
                test_params[key] = payload
                
            try:
                start_time = time.time()
                response = self.session.get(
                    urljoin(self.base_url, endpoint),
                    params=test_params,
                    timeout=10
                )
                elapsed = time.time() - start_time
                
                # Check for signs of SQL injection
                if any(indicator in response.text.lower() for indicator in 
                      ['sql', 'syntax', 'mysql', 'postgresql', 'oracle']):
                    self.vulnerabilities.append({
                        'type': 'SQL Injection',
                        'endpoint': endpoint,
                        'payload': payload,
                        'severity': 'CRITICAL'
                    })
                elif elapsed > 4:  # Time-based detection
                    self.vulnerabilities.append({
                        'type': 'Blind SQL Injection',
                        'endpoint': endpoint,
                        'payload': payload,
                        'severity': 'CRITICAL'
                    })
                    
            except requests.RequestException:
                continue
    
    def check_xss(self, endpoint: str, params: dict):
        """Test for XSS vulnerabilities"""
        xss_payloads = [
            "<script>alert('XSS')</script>",
            "<img src=x onerror=alert('XSS')>",
            "\"><script>alert('XSS')</script>"
        ]
        
        for payload in xss_payloads:
            test_params = params.copy()
            for key in test_params:
                test_params[key] = payload
                
            response = self.session.get(
                urljoin(self.base_url, endpoint),
                params=test_params
            )
            
            if payload in response.text:
                self.vulnerabilities.append({
                    'type': 'XSS',
                    'endpoint': endpoint,
                    'payload': payload,
                    'severity': 'HIGH'
                })
```

## 3. **Dependency Vulnerability Checker**

```python
# security/dependency_checker.py
import json
import subprocess
from typing import List, Dict
import requests

class DependencyChecker:
    def __init__(self):
        self.vulnerability_db_url = "https://api.osv.dev/v1/query"
    
    def check_python_dependencies(self, requirements_file: str = 'requirements.txt'):
        """Check Python dependencies for known vulnerabilities"""
        vulnerabilities = []
        
        # Parse requirements file
        with open(requirements_file, 'r') as f:
            dependencies = []
            for line in f:
                line = line.strip()
                if line and not line.startswith('#'):
                    # Parse package name and version
                    if '==' in line:
                        pkg, version = line.split('==')
                    elif '>=' in line:
                        pkg, version = line.split('>=')
                    else:
                        pkg = line.split('>')[0].split('<')[0]
                        version = None
                    
                    dependencies.append((pkg.strip(), version))
        
        # Check each dependency against OSV database
        for pkg, version in dependencies:
            try:
                response = requests.post(
                    self.vulnerability_db_url,
                    json={
                        "package": {"name": pkg, "ecosystem": "PyPI"},
                        "version": version
                    }
                )
                
                if response.status_code == 200:
                    data = response.json()
                    if 'vulns' in data:
                        for vuln in data['vulns']:
                            vulnerabilities.append({
                                'package': pkg,
                                'version': version,
                                'vulnerability': vuln.get('id'),
                                'summary': vuln.get('summary'),
                                'severity': self._determine_severity(vuln)
                            })
            except Exception as e:
                print(f"Error checking {pkg}: {e}")
        
        return vulnerabilities
    
    def _determine_severity(self, vuln: Dict) -> str:
        """Determine severity based on CVSS score"""
        if 'severity' in vuln:
            for severity in vuln['severity']:
                if severity['type'] == 'CVSS_V3':
                    score = float(severity['score'])
                    if score >= 9.0:
                        return 'CRITICAL'
                    elif score >= 7.0:
                        return 'HIGH'
                    elif score >= 4.0:
                        return 'MEDIUM'
        return 'LOW'
```

## 4. **Memory Safety and Race Condition Detector**

```python
# security/memory_checker.py
import threading
import time
from collections import defaultdict
from contextlib import contextmanager

class RaceConditionDetector:
    def __init__(self):
        self.shared_resources = defaultdict(list)
        self.lock = threading.Lock()
    
    @contextmanager
    def track_access(self, resource_name: str, thread_id: int):
        """Track access to shared resources"""
        start_time = time.time()
        
        with self.lock:
            self.shared_resources[resource_name].append({
                'thread': thread_id,
                'start': start_time,
                'end': None
            })
        
        try:
            yield
        finally:
            end_time = time.time()
            with self.lock:
                for access in self.shared_resources[resource_name]:
                    if (access['thread'] == thread_id and 
                        abs(access['start'] - start_time) < 0.001):
                        access['end'] = end_time
    
    def detect_race_conditions(self):
        """Detect potential race conditions"""
        race_conditions = []
        
        for resource, accesses in self.shared_resources.items():
            accesses.sort(key=lambda x: x['start'])
            
            for i in range(len(accesses) - 1):
                current = accesses[i]
                next_access = accesses[i + 1]
                
                # Check for overlapping access
                if (current['end'] is not None and 
                    next_access['start'] < current['end'] and
                    current['thread'] != next_access['thread']):
                    
                    race_conditions.append({
                        'resource': resource,
                        'thread1': current['thread'],
                        'thread2': next_access['thread'],
                        'overlap_start': next_access['start'],
                        'overlap_end': min(current['end'], next_access['end'] or float('inf'))
                    })
        
        return race_conditions
```

## 5. **Input Validation and Sanitization Checker**

```python
# security/input_validator.py
import re
from typing import Union, List

class InputValidator:
    def __init__(self):
        self.patterns = {
            'sql_injection': r"([';]+\s*(?:--|#|/\*|\*/|union|select|insert|update|delete|drop|alter))",
            'xss': r"(<script|javascript:|on\w+\s*=)",
            'path_traversal': r"(\.\./|\.\.\\|~/|/etc/|/bin/)",
            'command_injection': r"(;|\|\||&&|\$\(|\`|\n|\r)"
        }
    
    def sanitize_input(self, input_data: Union[str, List[str]], 
                      input_type: str = 'general') -> Union[str, List[str]]:
        """Sanitize input based on type"""
        if isinstance(input_data, list):
            return [self._sanitize_item(item, input_type) for item in input_data]
        return self._sanitize_item(input_data, input_type)
    
    def _sanitize_item(self, item: str, input_type: str) -> str:
        """Sanitize a single input item"""
        if not isinstance(item, str):
            return item
        
        # Remove null bytes
        item = item.replace('\x00', '')
        
        # Type-specific sanitization
        if input_type == 'sql':
            # Escape SQL special characters
            item = item.replace("'", "''")
            item = item.replace('"', '""')
            item = item.replace('\\', '\\\\')
        elif input_type == 'html':
            # HTML entity encoding
            item = (item.replace('&', '&amp;')
                       .replace('<', '&lt;')
                       .replace('>', '&gt;')
                       .replace('"', '&quot;')
                       .replace("'", '&#x27;'))
        elif input_type == 'path':
            # Prevent path traversal
            item = re.sub(r'\.\./|\.\.\\', '', item)
            item = re.sub(r'[<>:"|?*]', '', item)
        
        return item
    
    def validate_input(self, input_data: str, validation_type: str = 'email') -> bool:
        """Validate input against specific patterns"""
        validation_patterns = {
            'email': r'^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$',
            'phone': r'^\+?[\d\s\-\(\)]{10,}$',
            'url': r'^https?://[^\s/$.?#].[^\s]*$',
            'integer': r'^-?\d+$',
            'float': r'^-?\d+(?:\.\d+)?$',
            'alphanumeric': r'^[a-zA-Z0-9]+$',
            'uuid': r'^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'
        }
        
        if validation_type in validation_patterns:
            return bool(re.match(validation_patterns[validation_type], input_data))
        
        return True
```

## 6. **Security Headers Checker**

```python
# security/headers_checker.py
import requests
from typing import Dict, List

class SecurityHeadersChecker:
    REQUIRED_HEADERS = {
        'X-Content-Type-Options': 'nosniff',
        'X-Frame-Options': ['DENY', 'SAMEORIGIN'],
        'X-XSS-Protection': '1; mode=block',
        'Strict-Transport-Security': r'max-age=\d+',
        'Content-Security-Policy': None,  # Any value is good
        'Referrer-Policy': None,
        'Permissions-Policy': None
    }
    
    def check_headers(self, url: str) -> Dict[str, List[str]]:
        """Check security headers for a given URL"""
        issues = {'missing': [], 'weak': []}
        
        try:
            response = requests.get(url, timeout=10)
            headers = response.headers
            
            for header, expected_value in self.REQUIRED_HEADERS.items():
                if header not in headers:
                    issues['missing'].append(header)
                elif expected_value is not None:
                    if isinstance(expected_value, list):
                        if headers[header] not in expected_value:
                            issues['weak'].append(f"{header}: {headers[header]}")
                    elif isinstance(expected_value, str):
                        if not re.match(expected_value, headers[header], re.IGNORECASE):
                            issues['weak'].append(f"{header}: {headers[header]}")
        
        except requests.RequestException as e:
            issues['error'] = str(e)
        
        return issues
```

## 7. **Main Security Orchestrator**

```python
# security/orchestrator.py
import os
from pathlib import Path
from typing import Dict, List
import json

class SecurityOrchestrator:
    def __init__(self, project_path: str):
        self.project_path = Path(project_path)
        self.results = {
            'static_analysis': [],
            'dependency_vulnerabilities': [],
            'security_headers': [],
            'dynamic_tests': [],
            'race_conditions': []
        }
    
    def run_full_scan(self):
        """Run complete security scan"""
        print("Starting comprehensive security scan...")
        
        # 1. Static Analysis
        print("\n1. Running static analysis...")
        static_analyzer = StaticAnalyzer()
        for file_path in self.project_path.rglob("*.py"):
            with open(file_path, 'r') as f:
                content = f.read()
                static_analyzer.analyze_file(str(file_path), content)
        self.results['static_analysis'] = static_analyzer.vulnerabilities
        
        # 2. Dependency Check
        print("2. Checking dependencies...")
        dep_checker = DependencyChecker()
        req_file = self.project_path / 'requirements.txt'
        if req_file.exists():
            self.results['dependency_vulnerabilities'] = dep_checker.check_python_dependencies(
                str(req_file)
            )
        
        # 3. Generate Report
        self._generate_report()
        
        return self.results
    
    def _generate_report(self):
        """Generate comprehensive security report"""
        report = {
            'summary': {
                'total_vulnerabilities': sum(len(v) for v in self.results.values()),
                'critical': 0,
                'high': 0,
                'medium': 0,
                'low': 0
            },
            'details': self.results
        }
        
        # Count severities
        for vuln_list in self.results.values():
            for vuln in vuln_list:
                if isinstance(vuln, dict) and 'severity' in vuln:
                    severity = vuln['severity'].upper()
                    if severity == 'CRITICAL':
                        report['summary']['critical'] += 1
                    elif severity == 'HIGH':
                        report['summary']['high'] += 1
                    elif severity == 'MEDIUM':
                        report['summary']['medium'] += 1
                    elif severity == 'LOW':
                        report['summary']['low'] += 1
        
        # Save report
        report_path = self.project_path / 'security_report.json'
        with open(report_path, 'w') as f:
            json.dump(report, f, indent=2, default=str)
        
        print(f"\nSecurity report saved to: {report_path}")
        self._print_summary(report['summary'])
    
    def _print_summary(self, summary: Dict):
        """Print scan summary"""
        print("\n" + "="*

---