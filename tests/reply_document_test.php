<?php declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/compose.php';
$quote = ecw_quote_block(['body_html'=>'<html><head><meta http-equiv="Content-Type" content="text/html;charset=utf-8"></head><body><p>ORIGINAL MESSAGE</p></body></html>', 'from_email'=>'sender@example.test']);
$html = ecw_sanitize_compose_html('<p>MY NEW ANSWER</p>' . $quote);
if (!str_contains($html, 'MY NEW ANSWER') || strpos($html,'MY NEW ANSWER') > strpos($html,'ORIGINAL MESSAGE')) throw new RuntimeException('Reply was replaced by original HTML document');
echo "PASS full Outlook document quotation preserves the new answer through save and send sanitization\n";
