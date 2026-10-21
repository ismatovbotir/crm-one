<?php

/*
| Public bid-link pages and e-mails (Spec 08 8.2).
*/

return [

    'for' => 'Invitation for :name',
    'deadline' => 'Bids due: :at',
    'time_left' => ':time left',
    'notice' => 'Request for quotation',
    'my_bids' => 'Your bids and receipts',
    'no_bids' => 'You have not submitted a bid yet.',
    'receipt' => 'Receipt sha256 :hash',
    'withdraw' => 'Withdraw bid',
    'withdraw_reason' => 'Reason for withdrawal',
    'confirm_withdraw' => 'Withdraw your bid?',
    'withdrawn' => 'Your bid was withdrawn.',
    'verify_title' => 'Confirm it is you',
    'verify_hint' => 'We send a 6-digit code to :email. It is valid for 10 minutes.',
    'send_code' => 'Send code',
    'code' => 'Code',
    'verify' => 'Verify',
    'code_sent' => 'Code sent.',
    'code_invalid' => 'The code is wrong or expired.',
    'verified' => 'Verified. You can submit your bid.',
    'verify_first' => 'Verify with the code first.',
    'submit_title' => 'Your bid',
    'revise' => 'Revise your bid (a new version replaces the previous one)',
    'valid_until' => 'Bid valid until',
    'files' => 'Attachments (quote, certificates; PDF, JPG, PNG)',
    'conditions' => 'I accept the tender conditions (communications through this page, confidentiality)',
    'submit' => 'Submit bid',
    'submitted' => 'Bid received. Keep the receipt hash.',
    'ask_title' => 'Ask a question',
    'ask_hint' => 'Answers are published to all participants without your name.',
    'question' => 'Question',
    'ask' => 'Send question',
    'question_sent' => 'Question sent.',
    'decline_title' => 'Not bidding?',
    'decline_reason' => 'Reason',
    'decline' => 'Decline',
    'confirm_decline' => 'Decline this tender?',
    'declined' => 'You declined the tender.',
    'you_declined' => 'You declined this tender.',
    'closed' => 'The bid deadline has passed. You can still see your bids and receipts.',
    'mail' => [
        'invitation_subject' => 'Request for quotation :number',
        'greeting' => 'Dear :name,',
        'invitation_body' => ':company invites you to quote for :number “:title”. Bids are due :deadline.',
        'open' => 'Open the request and submit your bid',
        'do_not_forward' => 'This link is personal to your company. Do not forward it.',
        'code_subject' => 'Verification code for :number',
        'code_body' => 'Your verification code for request :number of :company:',
        'code_warning' => 'Valid for 10 minutes. Never share this code.',
    ],

];
