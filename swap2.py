import re

with open('c:/xampp/htdocs/ai_quality/dashboard.php', 'r', encoding='utf-8') as f:
    content = f.read()

# Let's find the exact indices
pattern = re.compile(r'(<div class="status-row">)(.*?)(</div><!-- /status-row -->)', re.DOTALL)
match = pattern.search(content)

if match:
    row_start = match.group(1)
    panels_raw = match.group(2)
    row_end = match.group(3)
    
    # Split the panels_raw by finding the headers
    # Each panel starts with '<!-- '
    # But let's be more precise and look for '<div class="panel">'
    panels = re.split(r'(?=<!-- .*?(?:Trend Forecast|Spike Detection|Daily Summary) -->)', panels_raw)
    
    # Filter out empty strings
    panels = [p for p in panels if p.strip()]
    
    if len(panels) >= 3:
        # Before: 0: Trend, 1: Spike, 2: Daily
        # We want: Daily, Spike, Trend
        swapped_panels = panels[2] + panels[1] + panels[0]
        
        new_content = content[:match.start()] + row_start + swapped_panels + row_end + content[match.end():]
        with open('c:/xampp/htdocs/ai_quality/dashboard.php', 'w', encoding='utf-8') as f:
            f.write(new_content)
        print('Swap completely successful!')
    else:
        print('Could not find 3 panels. Found:', len(panels))
else:
    print('Could not find status row')
