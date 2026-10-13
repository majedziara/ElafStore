<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Reset your password — Nella &amp; Vale</title>
</head>
<body>
    <main>
        <h1>Reset your password</h1>
        <form method="POST" action="{{ $action }}">
            @csrf
            <input type="hidden" name="email" value="{{ $email }}">
            <input type="hidden" name="token" value="{{ $token }}">
            <p><label>New password <input type="password" name="password" minlength="12" required autocomplete="new-password"></label></p>
            <p><label>Confirm password <input type="password" name="password_confirmation" minlength="12" required autocomplete="new-password"></label></p>
            <p>Use at least 12 characters, including letters and numbers.</p>
            <button type="submit">Reset password</button>
        </form>
    </main>
</body>
</html>
