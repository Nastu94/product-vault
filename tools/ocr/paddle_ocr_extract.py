import argparse
import json
import sys
from pathlib import Path

def disable_ssl_verification_for_local_model_downloads():
    """
    Workaround locale per ambienti Windows in cui Python non riesce
    a verificare i certificati SSL durante il download dei modelli PaddleOCR.

    Da usare solo in sviluppo locale.
    """
    import ssl

    ssl._create_default_https_context = ssl._create_unverified_context

    try:
        import requests
        import urllib3

        original_request = requests.sessions.Session.request

        def patched_request(self, method, url, **kwargs):
            kwargs["verify"] = False

            return original_request(self, method, url, **kwargs)

        requests.sessions.Session.request = patched_request
        urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)
    except Exception:
        pass

def normalize_text(text: str) -> str:
    return " ".join(str(text).replace("\n", " ").split()).strip()


def normalize_box(box):
    normalized = []

    for point in box:
        if isinstance(point, (list, tuple)) and len(point) >= 2:
            normalized.append([float(point[0]), float(point[1])])

    return normalized


def box_top(box) -> float:
    if not box:
        return 0.0

    return min(point[1] for point in box)


def box_left(box) -> float:
    if not box:
        return 0.0

    return min(point[0] for point in box)


def extract_lines_from_legacy_result(result):
    """
    Estrae righe dal formato classico PaddleOCR:
    [
      [
        [bbox, [text, confidence]],
        ...
      ]
    ]
    """
    lines = []

    if not result:
        return lines

    for page in result:
        if not page:
            continue

        for item in page:
            if not item or len(item) < 2:
                continue

            box = normalize_box(item[0])
            recognition = item[1]

            if not isinstance(recognition, (list, tuple)) or len(recognition) < 2:
                continue

            text = normalize_text(recognition[0])

            try:
                confidence = float(recognition[1])
            except Exception:
                confidence = 0.0

            if not text:
                continue

            lines.append({
                "text": text,
                "confidence": confidence,
                "bbox": box,
                "top": box_top(box),
                "left": box_left(box),
            })

    lines.sort(key=lambda line: (line["top"], line["left"]))

    return lines


def extract_lines_from_prediction_result(result):
    """
    Fallback per formati più recenti di PaddleOCR.
    Prova a leggere oggetti/dizionari che espongono rec_texts, rec_scores e rec_boxes.
    """
    lines = []

    if not result:
        return lines

    for page in result:
        data = None

        if isinstance(page, dict):
            data = page
        elif hasattr(page, "json"):
            try:
                data = page.json
            except Exception:
                data = None
        elif hasattr(page, "res"):
            try:
                data = page.res
            except Exception:
                data = None

        if not isinstance(data, dict):
            continue

        texts = data.get("rec_texts") or data.get("texts") or []
        scores = data.get("rec_scores") or data.get("scores") or []
        boxes = data.get("rec_boxes") or data.get("dt_polys") or data.get("boxes") or []

        for index, text in enumerate(texts):
            normalized_text = normalize_text(text)

            if not normalized_text:
                continue

            try:
                confidence = float(scores[index])
            except Exception:
                confidence = 0.0

            try:
                box = normalize_box(boxes[index])
            except Exception:
                box = []

            lines.append({
                "text": normalized_text,
                "confidence": confidence,
                "bbox": box,
                "top": box_top(box),
                "left": box_left(box),
            })

    lines.sort(key=lambda line: (line["top"], line["left"]))

    return lines


def build_ocr(lang: str):
    import os

    # Evita il controllo dei model hoster: i modelli ora sono già stati scaricati.
    os.environ["PADDLE_PDX_DISABLE_MODEL_SOURCE_CHECK"] = "True"

    # Workaround Windows/CPU: evita errori runtime legati a oneDNN/PIR.
    os.environ["FLAGS_use_mkldnn"] = "0"
    os.environ["FLAGS_enable_pir_api"] = "0"
    os.environ["FLAGS_allocator_strategy"] = "auto_growth"
    disable_ssl_verification_for_local_model_downloads()
    from paddleocr import PaddleOCR

    constructors = [
        lambda: PaddleOCR(
            lang=lang,
            use_doc_orientation_classify=False,
            use_doc_unwarping=False,
            use_textline_orientation=False,
        ),
        lambda: PaddleOCR(
            lang=lang,
            use_angle_cls=False,
        ),
        lambda: PaddleOCR(
            lang=lang,
        ),
    ]

    last_error = None

    for constructor in constructors:
        try:
            return constructor()
        except Exception as exception:
            last_error = exception

    raise last_error


def run_ocr(ocr, image_path: Path):
    """
    Prova prima il metodo legacy ocr().
    Se non funziona, prova predict().
    """
    try:
        return ocr.ocr(str(image_path)), "ocr"
    except Exception:
        pass

    if hasattr(ocr, "predict"):
        return ocr.predict(str(image_path)), "predict"

    raise RuntimeError("Nessun metodo OCR compatibile trovato: né ocr() né predict().")


def main():
    parser = argparse.ArgumentParser(description="PaddleOCR extractor for Product Vault")
    parser.add_argument("image_path", help="Path immagine da analizzare")
    parser.add_argument("--lang", default="it", help="Lingua PaddleOCR, es. it, en, latin")

    args = parser.parse_args()

    image_path = Path(args.image_path)

    if not image_path.is_file():
        print(json.dumps({
            "ok": False,
            "error": f"File non trovato: {image_path}",
        }, ensure_ascii=False))
        sys.exit(1)

    try:
        ocr = build_ocr(args.lang)

        result, api_mode = run_ocr(ocr, image_path)

        lines = extract_lines_from_legacy_result(result)

        if not lines:
            lines = extract_lines_from_prediction_result(result)

        raw_text = "\n".join(line["text"] for line in lines).strip()

        confidence_values = [
            line["confidence"]
            for line in lines
            if isinstance(line.get("confidence"), (int, float))
        ]

        average_confidence = (
            sum(confidence_values) / len(confidence_values)
            if confidence_values
            else 0
        )

        output = {
            "ok": True,
            "engine": "paddleocr",
            "lang": args.lang,
            "api_mode": api_mode,
            "confidence_score": round(average_confidence * 100),
            "raw_text": raw_text,
            "lines": [
                {
                    "text": line["text"],
                    "confidence": line["confidence"],
                    "bbox": line["bbox"],
                }
                for line in lines
            ],
            "metadata": {
                "line_count": len(lines),
                "average_confidence": average_confidence,
            },
        }

        print(json.dumps(output, ensure_ascii=False))

    except Exception as exception:
        print(json.dumps({
            "ok": False,
            "engine": "paddleocr",
            "lang": args.lang,
            "error": str(exception),
        }, ensure_ascii=False))
        sys.exit(1)


if __name__ == "__main__":
    main()