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

def text_tokens(value: str) -> List[str]:
    return [token for token in normalize_text(value).split(" ") if token]


def looks_like_model_token(token: str) -> bool:
    token = token.strip().lower()

    if len(token) < 2:
        return False

    return bool(re.search(r"[a-z]", token)) and bool(re.search(r"\d", token))


def is_generic_technical_token(token: str) -> bool:
    token = token.strip().lower()

    if not token:
        return True

    generic_patterns = [
        r"^\d+k$",  # 4k, 8k
        r"^\d+p$",  # 1080p
        r"^\d+(?:w|kw|mah|wh|gb|tb|mb|hz|mhz|ghz|m|cm|mm|l)$",  # 65w, 20000mah, 16gb, 1m, 6l
        r"^e\d{1,3}$",  # e27
        r"^ax\d{3,4}$",  # ax1800
        r"^x\d{1,3}$",  # x1
        r"^r\d{1,3}$",  # r14
    ]

    return any(re.match(pattern, token) for pattern in generic_patterns)


def model_tokens(value: str) -> List[str]:
    tokens = text_tokens(value)
    found: List[str] = []

    for token in tokens:
        if looks_like_model_token(token):
            found.append(token)

    for index, token in enumerate(tokens):
        next_token = tokens[index + 1] if index + 1 < len(tokens) else None
        third_token = tokens[index + 2] if index + 2 < len(tokens) else None

        if not next_token:
            continue

        if (
            re.match(r"^[a-z]{1,4}$", token)
            and len(next_token) >= 3
            and looks_like_model_token(next_token)
        ):
            found.append(token + next_token)

        if token == "gen" and re.match(r"^\d{1,3}$", next_token):
            found.append(token + next_token)

        if (
            third_token
            and re.match(r"^[a-z]{1,4}$", token)
            and re.match(r"^\d{2,5}$", next_token)
            and looks_like_model_token(third_token)
        ):
            found.append(next_token + third_token)
            found.append(token + next_token + third_token)

    return list(dict.fromkeys(found))


def strong_model_tokens(value: str) -> List[str]:
    return [
        token
        for token in model_tokens(value)
        if looks_like_model_token(token)
        and not is_generic_technical_token(token)
        and len(token) >= 5
    ]


def spec_tokens(value: str) -> List[str]:
    tokens = text_tokens(value)
    specs: List[str] = []

    for index, token in enumerate(tokens):
        if re.match(r"^\d+k$", token):
            specs.append(token)

        if re.match(r"^\d+(?:w|kw|mah|wh|gb|tb|mb|hz|mhz|ghz|m|cm|mm|l)$", token):
            specs.append(token)

        if token in {"dual", "triple", "quad", "doppio", "doppia", "triplo"}:
            specs.append(token)

        next_token = tokens[index + 1] if index + 1 < len(tokens) else None

        if next_token and re.match(r"^\d+$", token) and next_token in {"porta", "porte", "port", "ports"}:
            specs.append(token + "_" + next_token)

    return list(dict.fromkeys(specs))


def identity_guardrails(candidate_name: str, canonical_name: str) -> Dict[str, Any]:
    candidate_strong_models = strong_model_tokens(candidate_name)
    canonical_strong_models = strong_model_tokens(canonical_name)

    strong_model_overlap = sorted(set(candidate_strong_models) & set(canonical_strong_models))

    model_conflict = (
        bool(candidate_strong_models)
        and bool(canonical_strong_models)
        and not strong_model_overlap
    )

    candidate_specs = spec_tokens(candidate_name)
    canonical_specs = spec_tokens(canonical_name)

    spec_overlap = sorted(set(candidate_specs) & set(canonical_specs))

    spec_difference = (
        bool(candidate_specs)
        and bool(canonical_specs)
        and not spec_overlap
    )

    return {
        "candidate_model_tokens": model_tokens(candidate_name),
        "canonical_model_tokens": model_tokens(canonical_name),
        "candidate_strong_model_tokens": candidate_strong_models,
        "canonical_strong_model_tokens": canonical_strong_models,
        "strong_model_overlap": strong_model_overlap,
        "model_conflict": model_conflict,
        "candidate_spec_tokens": candidate_specs,
        "canonical_spec_tokens": canonical_specs,
        "spec_overlap": spec_overlap,
        "spec_difference": spec_difference,
    }


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

        guardrails = identity_guardrails(candidate_name, canonical_name)

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
            "identity_guardrails": guardrails,
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
    warnings: List[str] = []

    guardrails = best_match.get("identity_guardrails") or {}
    model_conflict = bool(guardrails.get("model_conflict"))
    spec_difference = bool(guardrails.get("spec_difference"))

    if best_match["similarity"] >= args.min_score:
        signals.append("high_similarity_to_global_canonical_name")

        if model_conflict:
            signals.append("candidate_name_similar_but_different_model")
            warnings.append("high_similarity_but_model_conflict")

        if spec_difference:
            signals.append("candidate_name_similar_but_spec_difference")
            warnings.append("high_similarity_but_spec_difference")

        if (
            normalize_text(candidate_name) != normalize_text(best_match["canonical_name"])
            and not model_conflict
            and not spec_difference
        ):
            signals.append("candidate_name_probably_ocr_variant")
    else:
        signals.append("low_similarity_to_global_canonical_name")

    if best_match["similarity"] >= 99.5 and not model_conflict and not spec_difference:
        signals.append("candidate_name_matches_global_canonical_name")

    result = {
        "version": VERSION,
        "enabled": True,
        "best_match": best_match,
        "matches": matches[:5],
        "signals": signals,
        "warnings": warnings,
    }

    print(json.dumps(result, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())