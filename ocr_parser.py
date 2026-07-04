#!/usr/bin/env python3
import sys
import re
import json
from PIL import Image
import pytesseract
import argparse

def extract_inn(text):
    match = re.search(r'(?<![+8\d])\b(\d{12}|\d{10})\b(?![\d])', text)
    return match.group(1) if match else None

def extract_phone(text):
    patterns = [
        r'\+7\s*\(?(\d{3})\)?[\s\-]?(\d{3})[\s\-]?(\d{2})[\s\-]?(\d{2})',
        r'8\s*\(?(\d{3})\)?[\s\-]?(\d{3})[\s\-]?(\d{2})[\s\-]?(\d{2})'
    ]
    for pattern in patterns:
        match = re.search(pattern, text)
        if match:
            return f"+7{match.group(1)}{match.group(2)}{match.group(3)}{match.group(4)}"
    match = re.search(r'(\d{3})[\s\-](\d{3})[\s\-](\d{2})[\s\-](\d{2})', text)
    if match:
        return f"+7{match.group(1)}{match.group(2)}{match.group(3)}{match.group(4)}"
    return None

def extract_address(text):
    lines = text.split('\n')
    candidates = []
    for i, line in enumerate(lines):
        if re.search(r'\b\d{6}\b', line) and re.search(r'[ММ]осква|[Сс]анкт|[гГ]\.|ул\.|пр\.|пер\.', line):
            addr_parts = [line.strip()]
            j = i + 1
            while j < len(lines):
                next_line = lines[j].strip()
                if not next_line:
                    break
                if re.search(r'(ул|пр|пер|д\.|кв\.|пос\.|ш\.|,|\.)', next_line, re.IGNORECASE):
                    addr_parts.append(next_line)
                    j += 1
                else:
                    break
            full_address = ' '.join(addr_parts)
            full_address = re.sub(r'[|]', '', full_address)
            full_address = re.sub(r'\s+', ' ', full_address).strip()
            full_address = re.sub(r'\s*\|.*$', '', full_address)
            if full_address:
                candidates.append(full_address)
    if candidates:
        return max(candidates, key=len)
    for line in lines:
        if 'Адрес:' in line:
            return line.strip()
    return None

def parse_image(image_path):
    try:
        img = Image.open(image_path)
        text = pytesseract.image_to_string(img, lang='rus')
        inn = extract_inn(text)
        phone = extract_phone(text)
        address = extract_address(text)
        result = {
            'inn': inn,
            'phone': phone,
            'address': address,
            'full_text': text
        }
        return json.dumps(result, ensure_ascii=False, indent=2)
    except Exception as e:
        return json.dumps({'error': str(e)}, ensure_ascii=False)

if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('image_path', help='Путь к файлу изображения')
    args = parser.parse_args()
    print(parse_image(args.image_path))
