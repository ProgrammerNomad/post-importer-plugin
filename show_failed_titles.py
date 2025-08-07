import json

with open('failed_imports_20250807_135324.json', 'r', encoding='utf-8') as f:
    data = json.load(f)

print(f'Total failed posts: {len(data)}')
print()
print('Failed post titles:')
print('=' * 80)

count = 0
for i, post in enumerate(data, 1):
    if 'title' in post and post['title']:
        count += 1
        index = post.get('index', '?')
        title = post['title']
        error = post.get('error', 'Unknown').strip()
        
        print(f'{count:3d}. [Index {index:4}] {title}')
        print(f'     Error: {error}')
        print()
        
        if count >= 50:  # Show first 50 titles
            remaining = sum(1 for p in data[i:] if 'title' in p and p['title'])
            if remaining > 0:
                print(f'... and {remaining} more failed posts')
            break

print(f'\nSummary: {count} posts with titles failed to import')
