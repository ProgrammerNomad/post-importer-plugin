import json
import re
import sys

def fix_common_json_errors(content):
    # Remove unescaped control characters (nulls, etc.)
    content = re.sub(r'[\x00-\x1F\x7F]', '', content)

    # Replace single quotes with double quotes when safe
    content = re.sub(r"(?<![a-zA-Z0-9])'(.*?)'(?![a-zA-Z0-9])", r'"\1"', content)

    # Remove trailing commas before closing brackets or braces
    content = re.sub(r',(\s*[}\]])', r'\1', content)

    # Fix newlines inside strings
    content = re.sub(r'(?<!\\)\\n', r'\\\\n', content)

    return content

def validate_and_fix_json(input_path, output_path):
    try:
        # ✅ Handles UTF-8 BOM
        with open(input_path, 'r', encoding='utf-8-sig') as f:
            content = f.read()

        try:
            # Try to parse the JSON directly
            data = json.loads(content)
            print("✅ JSON is valid.")
            return

        except json.JSONDecodeError as e:
            print(f"❌ JSON is invalid: {e}")
            print("🛠️ Attempting to fix...")

            fixed_content = fix_common_json_errors(content)

            try:
                data = json.loads(fixed_content)
                print("✅ Fix successful. Writing corrected file...")
                with open(output_path, 'w', encoding='utf-8') as f_out:
                    json.dump(data, f_out, indent=2, ensure_ascii=False)
                print(f"✅ Fixed JSON written to: {output_path}")
            except json.JSONDecodeError as e2:
                print(f"❌ Still invalid after fix: {e2}")
    except Exception as e:
        print(f"💥 Error: {e}")

if __name__ == "__main__":
    if len(sys.argv) < 2:
        print("Usage: python validate_and_fix_json.py input.json [output.json]")
        sys.exit(1)

    input_file = sys.argv[1]
    output_file = sys.argv[2] if len(sys.argv) > 2 else "fixed_output.json"

    validate_and_fix_json(input_file, output_file)
