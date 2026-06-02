import argparse
import json
import re
import sys
import unicodedata
from typing import Any, Dict, List, Optional


VERSION = "product_text_similarity_v1"


def normalize_text(value: Optional[str]) -> str:
    """
    Normalizza testo per confronto fuzzy.

    Non corregge semanticamente il testo.
    Riduce solo rumore di formattazione/OCR:
    - maiuscole/minuscole
    - accenti
    - punteggiatura
    - spazi multipli
    """
    if value is None:
        return ""

    text = str(value)
    text = unicodedata.normalize("NFKD", text)
    text = "".join(char for char in text if not unicodedata.combining(char))
    text = text.lower()
    text = re.sub(r"[^a-z0-9]+", " ", text)
    text = re.sub(r"\s+", " ", text).strip()

    return text


def read_payload() -> Dict[str, Any]:
    """
    Legge JSON da stdin.
    Laravel passerà il payload allo script tramite stdin.
    """
    raw = sys.stdin.read().strip()

    if not raw:
        return {}

    try:
        payload = json.loads(raw)
    except json.JSONDecodeError:
        return {}

    return payload if isinstance(payload, dict) else {}


def build_empty_result(
    *,
    enabled: bool,
    warnings: Optional[List[str]] = None,
    error: Optional[str] = None,
) -> Dict[str, Any]:
    """
    Restituisce sempre una struttura stabile.
    Lo script non deve rompere la pipeline Laravel se qualcosa manca.
    """
    result: Dict[str, Any] = {
        "version": VERSION,
        "enabled": enabled,
        "best_match": None,
        "signals": [],
        "warnings": warnings or [],
    }

    if error:
        result["error"] = error[:500]

    return result


def safe_score(value: Any) -> float:
    try:
        return float(value)
    except Exception:
        return 0.0


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--min-score", type=float, default=80.0)
    args = parser.parse_args()

    payload = read_payload()

    candidate_name = str(payload.get("candidate_name") or "").strip()
    global_facts = payload.get("global_facts") or []

    if not candidate_name:
        print(json.dumps(
            build_empty_result(enabled=True, warnings=["missing_candidate_name"]),
            ensure_ascii=False,
        ))
        return 0

    if not isinstance(global_facts, list) or not global_facts:
        print(json.dumps(
            build_empty_result(enabled=True, warnings=["missing_global_facts"]),
            ensure_ascii=False,
        ))
        return 0

    try:
        from rapidfuzz import fuzz
    except Exception as exception:
        print(json.dumps(
            build_empty_result(
                enabled=True,
                warnings=["rapidfuzz_not_available"],
                error=str(exception),
            ),
            ensure_ascii=False,
        ))
        return 0

    candidate_normalized = normalize_text(candidate_name)
    matches: List[Dict[str, Any]] = []

    for fact in global_facts:
        if not isinstance(fact, dict):
            continue

        canonical_name = str(fact.get("canonical_name") or "").strip()

        if not canonical_name:
            continue

        canonical_normalized = normalize_text(canonical_name)

        if not canonical_normalized:
            continue

        token_set_similarity = safe_score(
            fuzz.token_set_ratio(candidate_normalized, canonical_normalized)
        )

        token_sort_similarity = safe_score(
            fuzz.token_sort_ratio(candidate_normalized, canonical_normalized)
        )

        partial_similarity = safe_score(
            fuzz.partial_ratio(candidate_normalized, canonical_normalized)
        )

        weighted_similarity = safe_score(
            fuzz.WRatio(candidate_normalized, canonical_normalized)
        )

        scored_methods = [
            ("rapidfuzz_token_set_ratio", token_set_similarity),
            ("rapidfuzz_token_sort_ratio", token_sort_similarity),
            ("rapidfuzz_partial_ratio", partial_similarity),
            ("rapidfuzz_wratio", weighted_similarity),
        ]

        method, similarity = max(scored_methods, key=lambda item: item[1])

        matches.append({
            "canonical_name": canonical_name,
            "suggested_category": fact.get("suggested_category"),
            "suggested_line_type": fact.get("suggested_line_type"),
            "confidence": fact.get("confidence"),
            "similarity": round(similarity, 2),
            "method": method,
            "scores": {
                "token_set_ratio": round(token_set_similarity, 2),
                "token_sort_ratio": round(token_sort_similarity, 2),
                "partial_ratio": round(partial_similarity, 2),
                "wratio": round(weighted_similarity, 2),
            },
        })

    if not matches:
        print(json.dumps(
            build_empty_result(enabled=True, warnings=["no_comparable_global_facts"]),
            ensure_ascii=False,
        ))
        return 0

    matches = sorted(matches, key=lambda item: item["similarity"], reverse=True)
    best_match = matches[0]

    signals: List[str] = []

    if best_match["similarity"] >= args.min_score:
        signals.append("high_similarity_to_global_canonical_name")

        if normalize_text(candidate_name) != normalize_text(best_match["canonical_name"]):
            signals.append("candidate_name_probably_ocr_variant")
    else:
        signals.append("low_similarity_to_global_canonical_name")

    if best_match["similarity"] >= 99.5:
        signals.append("candidate_name_matches_global_canonical_name")

    result = {
        "version": VERSION,
        "enabled": True,
        "best_match": best_match,
        "matches": matches[:5],
        "signals": signals,
        "warnings": [],
    }

    print(json.dumps(result, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())