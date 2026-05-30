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

def get_image_size(image_path: Path):
    """
    Restituisce larghezza e altezza dell'immagine, se disponibili.

    Non deve bloccare l'OCR se PIL non è disponibile o se il file non è leggibile.
    """
    try:
        from PIL import Image

        with Image.open(image_path) as image:
            return image.size
    except Exception:
        return None, None


def box_bounds(box):
    """
    Converte una bbox/poligono PaddleOCR in coordinate rettangolari assolute.
    """
    if not box:
        return {
            "x1": None,
            "y1": None,
            "x2": None,
            "y2": None,
            "width": None,
            "height": None,
            "center_x": None,
            "center_y": None,
        }

    xs = [point[0] for point in box]
    ys = [point[1] for point in box]

    x1 = float(min(xs))
    y1 = float(min(ys))
    x2 = float(max(xs))
    y2 = float(max(ys))

    width = x2 - x1
    height = y2 - y1

    return {
        "x1": x1,
        "y1": y1,
        "x2": x2,
        "y2": y2,
        "width": width,
        "height": height,
        "center_x": x1 + (width / 2),
        "center_y": y1 + (height / 2),
    }


def normalized_bounds(bounds, image_width, image_height):
    """
    Aggiunge coordinate normalizzate 0-1, se dimensioni immagine disponibili.
    """
    if not image_width or not image_height:
        return {}

    keys = ["x1", "y1", "x2", "y2", "width", "height", "center_x", "center_y"]
    normalized = {}

    for key in keys:
        value = bounds.get(key)

        if value is None:
            normalized[f"{key}_norm"] = None
            continue

        if key in ["x1", "x2", "width", "center_x"]:
            normalized[f"{key}_norm"] = round(float(value) / float(image_width), 6)
        else:
            normalized[f"{key}_norm"] = round(float(value) / float(image_height), 6)

    return normalized

def box_top(box) -> float:
    if not box:
        return 0.0

    return min(point[1] for point in box)


def box_left(box) -> float:
    if not box:
        return 0.0

    return min(point[0] for point in box)


def extract_lines_from_legacy_result(result, image_width=None, image_height=None):
    """
    Estrae item OCR dal formato classico PaddleOCR:
    [
      [
        [bbox, [text, confidence]],
        ...
      ]
    ]

    Mantiene sia l'ordine originale PaddleOCR sia le coordinate assolute.
    """
    items = []

    if not result:
        return items

    global_order = 0

    for page_index, page in enumerate(result):
        page_number = page_index + 1

        if not page:
            continue

        for page_order, item in enumerate(page):
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

            bounds = box_bounds(box)

            items.append({
                "id": global_order + 1,
                "page": page_number,
                "text": text,
                "confidence": confidence,
                "confidence_score": round(confidence * 100),
                "bbox": box,
                **bounds,
                **normalized_bounds(bounds, image_width, image_height),
                "original_order": global_order,
                "page_order": page_order,
                "source": "legacy",
            })

            global_order += 1

    return items


def extract_lines_from_prediction_result(result, image_width=None, image_height=None):
    """
    Fallback per formati più recenti di PaddleOCR.
    Prova a leggere oggetti/dizionari che espongono rec_texts, rec_scores e rec_boxes.
    """
    items = []

    if not result:
        return items

    global_order = 0

    for page_index, page in enumerate(result):
        page_number = page_index + 1
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

        for page_order, text in enumerate(texts):
            normalized_text = normalize_text(text)

            if not normalized_text:
                continue

            try:
                confidence = float(scores[page_order])
            except Exception:
                confidence = 0.0

            try:
                box = normalize_box(boxes[page_order])
            except Exception:
                box = []

            bounds = box_bounds(box)

            items.append({
                "id": global_order + 1,
                "page": page_number,
                "text": normalized_text,
                "confidence": confidence,
                "confidence_score": round(confidence * 100),
                "bbox": box,
                **bounds,
                **normalized_bounds(bounds, image_width, image_height),
                "original_order": global_order,
                "page_order": page_order,
                "source": "prediction",
            })

            global_order += 1

    return items

def sorted_by_reading_order(items):
    """
    Ordine di lettura semplice: pagina, y, x.
    Mantiene un comportamento simile a quello attuale.
    """
    return sorted(
        items,
        key=lambda item: (
            item.get("page") or 1,
            item.get("y1") if item.get("y1") is not None else 0,
            item.get("x1") if item.get("x1") is not None else 0,
        )
    )


def median(values):
    values = sorted([value for value in values if value is not None])

    if not values:
        return 0

    middle = len(values) // 2

    if len(values) % 2:
        return values[middle]

    return (values[middle - 1] + values[middle]) / 2


def group_visual_lines(items):
    """
    Raggruppa gli item OCR in righe visive usando la coordinata y.

    Non è ancora un parser tabellare.
    Serve solo a non perdere l'informazione spaziale necessaria ai parser futuri.
    """
    readable_items = [
        item for item in sorted_by_reading_order(items)
        if item.get("center_y") is not None
    ]

    if not readable_items:
        return []

    median_height = median([item.get("height") for item in readable_items])
    y_threshold = max(6.0, median_height * 0.45)

    groups = []

    for item in readable_items:
        item_center_y = item.get("center_y")

        matching_group = None

        for group in groups:
            if abs(group["center_y"] - item_center_y) <= y_threshold:
                matching_group = group
                break

        if matching_group is None:
            groups.append({
                "page": item.get("page") or 1,
                "center_y": item_center_y,
                "items": [item],
            })
        else:
            matching_group["items"].append(item)
            matching_group["center_y"] = sum(
                child.get("center_y") or 0 for child in matching_group["items"]
            ) / len(matching_group["items"])

    visual_lines = []

    for index, group in enumerate(groups):
        line_items = sorted(
            group["items"],
            key=lambda item: item.get("x1") if item.get("x1") is not None else 0
        )

        x1_values = [item.get("x1") for item in line_items if item.get("x1") is not None]
        y1_values = [item.get("y1") for item in line_items if item.get("y1") is not None]
        x2_values = [item.get("x2") for item in line_items if item.get("x2") is not None]
        y2_values = [item.get("y2") for item in line_items if item.get("y2") is not None]
        confidence_values = [
            item.get("confidence") for item in line_items
            if isinstance(item.get("confidence"), (int, float))
        ]

        visual_lines.append({
            "id": index + 1,
            "page": group["page"],
            "text": " ".join(item["text"] for item in line_items).strip(),
            "item_ids": [item["id"] for item in line_items],
            "x1": min(x1_values) if x1_values else None,
            "y1": min(y1_values) if y1_values else None,
            "x2": max(x2_values) if x2_values else None,
            "y2": max(y2_values) if y2_values else None,
            "center_y": group["center_y"],
            "average_confidence": (
                sum(confidence_values) / len(confidence_values)
                if confidence_values
                else 0
            ),
        })

    return visual_lines

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

        image_width, image_height = get_image_size(image_path)

        items = extract_lines_from_legacy_result(
            result,
            image_width=image_width,
            image_height=image_height,
        )

        if not items:
            items = extract_lines_from_prediction_result(
                result,
                image_width=image_width,
                image_height=image_height,
            )

        reading_items = sorted_by_reading_order(items)

        for reading_order, item in enumerate(reading_items):
            item["reading_order"] = reading_order

        visual_lines = group_visual_lines(items)

        raw_text = "\n".join(item["text"] for item in reading_items).strip()

        confidence_values = [
            item["confidence"]
            for item in reading_items
            if isinstance(item.get("confidence"), (int, float))
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

            # Compatibilità con Laravel attuale.
            "lines": [
                {
                    "text": item["text"],
                    "confidence": item["confidence"],
                    "bbox": item["bbox"],
                }
                for item in reading_items
            ],

            # Nuovo output strutturato.
            "items": reading_items,
            "layout": {
                "image": {
                    "path": str(image_path),
                    "width": image_width,
                    "height": image_height,
                },
                "visual_lines": visual_lines,
            },

            "metadata": {
                "line_count": len(reading_items),
                "item_count": len(reading_items),
                "visual_line_count": len(visual_lines),
                "average_confidence": average_confidence,
                "image_width": image_width,
                "image_height": image_height,
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