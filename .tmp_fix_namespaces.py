from pathlib import Path
root = Path(r'c:\Users\HP\Documents\projects\full_vtu\backend\app')
for p in root.rglob('*.php'):
    text = p.read_text(encoding='utf-8')
    new = text.replace('namespace App\\Class\\', 'namespace App\\Classes\\')
    new = new.replace('namespace App\\Class;', 'namespace App\\Classes;')
    new = new.replace('use App\\Class\\', 'use App\\Classes\\')
    if new != text:
        p.write_text(new, encoding='utf-8')
        print('Updated', p)
