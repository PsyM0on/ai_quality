import re

with open('c:/xampp/htdocs/ai_quality/dashboard.php', 'r', encoding='utf-8') as f:
    content = f.read()

trend_match = re.search(r'(<!-- .*?Trend Forecast -->.*?)(?=<!-- .*?Spike Detection -->)', content, flags=re.DOTALL)
spike_match = re.search(r'(<!-- .*?Spike Detection -->.*?)(?=<!-- .*?Daily Summary -->)', content, flags=re.DOTALL)
daily_match = re.search(r'(<!-- .*?Daily Summary -->.*?)(?=</div><!-- /status-row -->)', content, flags=re.DOTALL)

if trend_match and spike_match and daily_match:
    trend_html = trend_match.group(1)
    spike_html = spike_match.group(1)
    daily_html = daily_match.group(1)
    
    # Construct the new swapped order
    new_status_row = daily_html + spike_html + trend_html
    
    # Replace in file
    old_status_row = trend_html + spike_html + daily_html
    new_content = content.replace(old_status_row, new_status_row)
    
    with open('c:/xampp/htdocs/ai_quality/dashboard.php', 'w', encoding='utf-8') as f:
        f.write(new_content)
    print('Swap successful')
else:
    print('Could not find all blocks')
