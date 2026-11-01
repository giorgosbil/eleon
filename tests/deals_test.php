<?php declare(strict_types=1);
// Deals: recognising RFQ / offer (revisions) / PO / confirmation / delivery / invoice in one email (lib/deals.php).
if (PHP_SAPI !== 'cli') exit;
require_once dirname(__DIR__) . '/lib/deals.php';
$n = 0;
$check = static function (bool $ok, string $what) use (&$n): void { if (!$ok) throw new RuntimeException('FAIL ' . $what); $n++; };
$mail = static fn(array $m): array => $m + ['subject' => '', 'body' => '', 'direction' => 'incoming', 'role' => null, 'sent' => '2026-10-01 10:00:00', 'attachments' => [], 'own_domains' => ['inquality.gr']];

// RFQ, English and Greek, from a customer: we sell.
$d = ecw_deal_detect($mail(['subject' => 'Request for quotation - load cells', 'body' => "Dear George,\nCould you please send us your best price for 4 x 250N load cells, delivery by end of November?\nThanks"]));
$check($d !== null && $d['stage'] === 'rfq' && $d['side'] === 'sell', 'English RFQ, sell');
$check(str_contains((string)$d['summary'], '250N'), 'RFQ summary is the ask: ' . $d['summary']);
$d = ecw_deal_detect($mail(['subject' => 'Προσφορά', 'body' => 'Καλημέρα σας, θα θέλαμε μια προσφορά για 2 σκληρόμετρα HV10 με παράδοση εντός του μήνα.']));
$check($d !== null && $d['stage'] === 'rfq', 'Greek RFQ');
// Our RFQ to a supplier: we buy.
$d = ecw_deal_detect($mail(['subject' => 'RFQ-2231 spare parts', 'body' => 'Please quote the parts below.', 'direction' => 'outgoing', 'role' => 'supplier']));
$check($d['stage'] === 'rfq' && $d['side'] === 'buy' && $d['ref'] === 'RFQ-2231', 'our RFQ to a supplier, its number');

// Offer with revision in the file name and a total in the PDF; a reply subject "RE: RFQ" does not make it an RFQ.
$d = ecw_deal_detect($mail(['subject' => 'RE: RFQ - load cells', 'direction' => 'outgoing', 'body' => 'Please find attached our revised offer.',
    'attachments' => [['id' => 7, 'name' => 'Quotation_Q-2026-014_Rev2.pdf', 'text' => "QUOTATION\nQuotation No: Q-2026-014\n4 x Load cell 250N 1.150,00\nGrand Total: 4.600,00 €\nValid until 31/12/2026"]]]));
$check($d['stage'] === 'offer' && $d['side'] === 'sell', 'offer, not RFQ, despite "RE: RFQ"');
$check($d['ref'] === 'Q-2026-014' && $d['revision'] === 2, 'offer number and revision: ' . $d['ref'] . ' r' . $d['revision']);
$check($d['amount'] === 4600.0 && $d['currency'] === 'EUR', 'offer total 4.600,00 €: ' . var_export($d['amount'], true));
$check($d['valid_until'] === '2026-12-31' && $d['attachment_id'] === 7, 'validity and the file');
// "valid for 30 days" counts from the email's date.
$d = ecw_deal_detect($mail(['subject' => 'Our offer 5512', 'direction' => 'outgoing', 'body' => "Our offer no 5512 is attached. Total: EUR 1,250.00. Offer valid for 30 days."]));
$check($d['stage'] === 'offer' && $d['amount'] === 1250.0 && $d['valid_until'] === '2026-10-31', 'English number format and validity in days');

// PO: a whole code (PO-1063), our own letterhead in the file decides the side (we buy), whoever forwarded it.
$d = ecw_deal_detect($mail(['subject' => 'RE: X200 calibration', 'role' => 'customer', 'body' => 'See our PO attached.',
    'attachments' => [['id' => 9, 'name' => 'PO-1063_SCIAPS.pdf', 'text' => "TO: PURCHASE ORDER\nSCIAPS EUROPE BV PO-0000001063\nEmail: sales.support@inquality.gr\nIt is our pleasure to place our order\ninfo@inquality.gr www.inquality.gr"]]]));
$check($d['stage'] === 'po' && $d['side'] === 'buy', 'our PO (letterhead) is buying even in a customer conversation');
$check(in_array($d['ref'], ['PO-0000001063', 'PO-1063'], true), 'PO code: ' . $d['ref']);
// A customer's PO to us: we sell.
$d = ecw_deal_detect($mail(['subject' => 'Purchase Order 4500123', 'body' => 'We are pleased to place our order for the items in your quotation Q-2026-014. Total amount: 4.600,00 EUR']));
$check($d['stage'] === 'po' && $d['side'] === 'sell' && $d['amount'] === 4600.0, 'customer PO, sell, amount');

// A customer case with the supplier in it too: the supplier's quote we forward stays BUYING (their letterhead),
// our own offer in the same case stays SELLING (ours).
$supplierQuote = ['id' => 21, 'name' => 'Quotation_SQ-778.pdf', 'text' => "QUOTATION SQ-778\nSCIAPS Europe BV\nsales@sciaps.com\nTotal: 3.100,00 EUR\nwww.sciaps.com"];
$d = ecw_deal_detect($mail(['subject' => 'FW: Quotation SQ-778', 'direction' => 'outgoing', 'role' => 'customer', 'body' => 'FYI, the supplier quote.', 'attachments' => [$supplierQuote]]));
$check($d['stage'] === 'offer' && $d['side'] === 'buy' && $d['amount'] === 3100.0, 'supplier quote forwarded in a customer conversation is buying');
$d = ecw_deal_detect($mail(['subject' => 'Our offer Q-2026-020', 'direction' => 'outgoing', 'role' => 'supplier', 'body' => 'Please find attached our offer.',
    'attachments' => [['id' => 22, 'name' => 'Offer_Q-2026-020.pdf', 'text' => "OFFER Q-2026-020\nInQuality G.P.\ninfo@inquality.gr\nTotal: 4.000,00 €\nwww.inquality.gr"]]]));
$check($d['side'] === 'sell', 'our offer is selling even if the conversation was marked supplier');
$check(ecw_deal_issuer("a@acme.com b www.acme.com", ['inquality.gr']) === 'them' && ecw_deal_issuer("info@inquality.gr www.inquality.gr", ['inquality.gr']) === 'us'
    && ecw_deal_issuer("To: info@inquality.gr from bob@acme.com", ['inquality.gr']) === null, 'letterhead: us, them, unknown');

// Confirmation, delivery with tracking, invoice.
$d = ecw_deal_detect($mail(['subject' => 'Order confirmation SO-88231', 'direction' => 'outgoing', 'body' => 'We confirm receipt of your order. Delivery date: 15.11.2026']));
$check($d['stage'] === 'confirm' && $d['side'] === 'sell' && $d['delivery_date'] === '2026-11-15', 'confirmation and its delivery date');
$d = ecw_deal_detect($mail(['subject' => 'Shipment of your order', 'body' => 'Your goods have been shipped via DHL. Tracking number: 1234567890']));
$check($d['stage'] === 'delivery' && $d['tracking'] === '1234567890' && $d['side'] === 'buy', 'delivery with tracking, from the supplier');
$d = ecw_deal_detect($mail(['subject' => 'Invoice INV-55120', 'body' => 'Please find attached our invoice.']));
$check($d['stage'] === 'invoice' && $d['ref'] === 'INV-55120', 'invoice');

// Not deals: a passing word in a footer or form is not a delivery or an invoice.
$check(ecw_deal_detect($mail(['subject' => 'Re: S4: Φόρμα υποστήριξης πελάτη', 'body' => 'Το όργανο δεν μετράει. Για αποστολή επισυνάψτε δελτίο αποστολής.'])) === null, 'support form footer is not a delivery');
$check(ecw_deal_detect($mail(['subject' => 'Meeting tomorrow', 'body' => 'See you at 10. Το τιμολόγιο θα το δούμε μετά.'])) === null, 'a mention of τιμολόγιο is not an invoice');
$check(ecw_deal_detect($mail(['subject' => 'Lunch?', 'body' => 'Are you free on Friday?'])) === null, 'ordinary email');

// Helpers.
$check(ecw_deal_number('1.234,56') === 1234.56 && ecw_deal_number('1,234.56') === 1234.56 && ecw_deal_number('12.500') === 12500.0 && ecw_deal_number('12,5') === 12.5, 'number formats');
$check(ecw_deal_date('31.12.26') === '2026-12-31' && ecw_deal_date('2026-02-30') === null, 'dates');
$check(ecw_deal_find_revision('Offer_Q14_v3.pdf', true) === 3 && ecw_deal_find_revision('Rev. B') === 2 && ecw_deal_find_revision('αναθεώρηση 4') === 4, 'revisions');
$check(ecw_deal_side('offer', 'incoming', null) === 'buy' && ecw_deal_side('po', 'incoming', null) === 'sell' && ecw_deal_side('rfq', 'incoming', 'supplier') === 'buy', 'sides');

// The company's own ERP offer sheet (Greek Ν in "Νο."), and what a web form asks.
$sheetText = "Προς: NAVINDUS  ΠΡΟΣΦΟΡΑ\n  Νο.: 08224  Ημ/νία: 14/10/2026  Σελ: 1/2\n\n  Τιμή Μον.  Σύνολο\n  Κωδικός / Περιγραφή  Ποσ.\n  (€)  (€)\n  1.  900-500166\n"
    . "  IE Battery Pack, Li Ion, 3.5AH, NGL - SCIAPS  1  290.00 ( € )  290.00 ( € )\n  2.  CERT-001\n  Διακρίβωση  2  50.00 ( € )  100.00 ( € )\n\n  Ισχύς Προσφοράς:  30 μέρες\n";
$sheet = ecw_deal_offer_sheet($sheetText);
$check($sheet !== null && $sheet['ref'] === '08224' && count($sheet['items']) === 2 && $sheet['amount'] === null && $sheet['valid_days'] === 30
    && $sheet['items'][0]['desc'] === '900-500166 · IE Battery Pack, Li Ion, 3.5AH, NGL - SCIAPS', 'our ERP offer: number, lines, validity; two lines and no printed total: no total shown');
$check(ecw_deal_offer_sheet($sheetText . "  Σύνολο:  390.00 ( € )
")['amount'] === 390.0, 'a printed total equal to the lines is shown');
$check(ecw_deal_offer_sheet($sheetText . "  Σύνολο:  290.00 ( € )
")['amount'] === null, 'a printed total that is not the sum of the lines is not trusted');
$one = "ΠΡΟΣΦΟΡΑ
  Νο.: 08224
  1.  900-500166
  IE Battery Pack  1  290.00 ( € )  290.00 ( € )
";
$check(ecw_deal_offer_sheet($one)['amount'] === 290.0, 'one priced line and no other price: its total is shown');
$check(ecw_deal_offer_sheet($one . "Προαιρετικά: φορτιστής 45.00 ( € )
")['amount'] === null, 'an optional item: no total');
$check(ecw_deal_find_amount("Price: € 168,815,-
Discount due to the trade-in: € 15.000,-
Price: € 5.500,-
Final Price: € 2.725,-") === [null, null], 'several prices, a discount, final prices: no total');
$check(ecw_deal_find_amount("Grand total: 4.600,00 EUR")[0] === 4600.0, 'one printed total: shown');
$check(ecw_deal_form_request("Από: Μ. Μ. m@x.gr\nΕίδος εξοπλισμού: Οθόνη ζυγού\nΘέμα: Αλλαγή Display\nΠεριγραφή αιτήματος: Στείλτε προσφορά για αντικατάσταση της οθόνης")
    === 'Οθόνη ζυγού: Στείλτε προσφορά για αντικατάσταση της οθόνης', 'a web form RFQ shows what is asked, not its first line');

echo "PASS deals: {$n} checks (RFQ / offer revisions / PO / confirmation / delivery / invoice, Greek and English, sides, letterhead, numbers)\n";
