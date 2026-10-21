<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Language Lines
    |--------------------------------------------------------------------------
    |
    | Framework lines (failed, password, throttle) plus the sign-in, forgot and
    | reset password screens.
    |
    */

    'failed' => 'These credentials do not match our records.',
    'password' => 'The provided password is incorrect.',
    'throttle' => 'Too many login attempts. Please try again in :seconds seconds.',
    'inactive' => 'Your account is not active. Contact an administrator.',

    'tagline' => 'From first lead to signed agreement, in one place.',
    'tagline_sub' => 'Items, leads, offers, orders and agreements with a full audit trail.',

    'sign_in' => 'Sign in',
    'sign_in_intro' => 'Use the account your administrator created for you.',
    'signing_in' => 'Signing in...',
    'remember_me' => 'Remember me',
    'forgot_password_link' => 'Forgot password?',

    'forgot_title' => 'Forgot password',
    'forgot_intro' => 'Enter your email and we will send you a reset link.',
    'send_reset_link' => 'Email reset link',
    'reset_link_sent' => 'If an account exists for that address, a reset link has been sent.',
    'back_to_sign_in' => 'Back to sign in',

    'reset_title' => 'Reset password',
    'reset_intro' => 'Choose a new password for your account.',
    'reset_submit' => 'Reset password',

    'otp' => [
        'cooldown' => 'Please wait :seconds seconds before requesting a new code.',
    ],

    'two_factor' => [
        'title' => 'Two-step verification',
        'intro' => 'Enter the 6-digit code from your authenticator app, or one of your recovery codes.',
        'code' => 'Code',
        'code_hint' => '6 digits from the app, or a recovery code like ABCDE-12345.',
        'verify' => 'Verify',
        'back' => 'Back to sign-in',
        'invalid' => 'The code is wrong or was already used.',
        'throttled' => 'Too many attempts. Try again in :seconds seconds.',
        'required' => 'Your role requires two-step verification. Set it up below to continue.',
        'section' => 'Two-step verification (2FA)',
        'on' => 'On',
        'off' => 'Off',
        'explain' => 'Protect your account with a code from an authenticator app (Google Authenticator, Microsoft Authenticator, Aegis) in addition to your password.',
        'enable' => 'Set up two-step verification',
        'step_app' => 'Install an authenticator app on your phone.',
        'step_secret' => 'Add an account with this key (or open the link on your phone):',
        'open_app' => 'Open in authenticator app',
        'step_code' => 'Enter the 6-digit code the app shows.',
        'confirm' => 'Confirm and turn on',
        'enabled' => 'Two-step verification is on. Keep your recovery codes safe.',
        'codes_title' => 'Recovery codes',
        'codes_hint' => 'Each code works once if you lose your phone. They are shown only now; store them somewhere safe.',
        'remaining' => 'Unused recovery codes: :count',
        'current_code' => 'Current code from the app or a recovery code',
        'regenerate' => 'New recovery codes',
        'codes_regenerated' => 'New recovery codes created; the old ones no longer work.',
        'disable' => 'Turn off',
        'confirm_disable' => 'Turn off two-step verification for your account?',
        'disabled' => 'Two-step verification is off.',
        'cannot_disable' => 'Two-step verification is required for your role and cannot be turned off.',
    ],

];
