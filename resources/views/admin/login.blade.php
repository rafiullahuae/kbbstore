<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Sign in · K-Beauty Bliss admin</title>
<style>
  :root{
    --accent:#15a85a; --accent-strong:#0f8f4b; --accent-soft:#e7f6ee;
    --ink:#14201a; --ink-2:#3a4a42; --ink-soft:#6b7a72;
    --surface:#ffffff; --surface-2:#f4f8f5; --border:#e2ebe5; --danger:#d6455a;
  }
  *{box-sizing:border-box}
  body{margin:0;min-height:100vh;display:grid;place-items:center;
    font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;
    color:var(--ink);background:linear-gradient(160deg,#eef7f1,#f4f8f5 60%,#e9f4ee)}
  .card{width:100%;max-width:400px;background:var(--surface);border:1px solid var(--border);
    border-radius:18px;padding:34px 30px;box-shadow:0 24px 60px -30px rgba(16,60,40,.35);margin:20px}
  .brand{display:flex;align-items:center;gap:11px;margin-bottom:22px}
  .logo{width:40px;height:40px;border-radius:11px;background:var(--accent);color:#fff;
    display:grid;place-items:center;font-weight:800;font-size:17px;letter-spacing:.5px}
  .brand b{font-size:16px}
  .brand small{display:block;color:var(--ink-soft);font-size:12px;font-weight:500}
  h1{font-size:19px;margin:0 0 4px}
  .sub{color:var(--ink-soft);font-size:13px;margin:0 0 20px}
  label{display:block;font-size:12.5px;font-weight:600;color:var(--ink-2);margin:0 0 6px}
  .fld{margin-bottom:15px}
  input[type=email],input[type=password]{width:100%;padding:11px 13px;border:1px solid var(--border);
    border-radius:11px;font-size:14px;background:var(--surface-2);color:var(--ink);outline:none}
  input:focus{border-color:var(--accent);background:var(--surface);box-shadow:0 0 0 3px var(--accent-soft)}
  .row{display:flex;align-items:center;justify-content:space-between;margin:2px 0 20px;font-size:12.5px}
  .row label{display:flex;align-items:center;gap:7px;font-weight:500;color:var(--ink-soft);margin:0;cursor:pointer}
  .btn{width:100%;padding:12px;border:0;border-radius:11px;background:var(--accent);color:#fff;
    font-size:14px;font-weight:700;cursor:pointer;transition:.15s}
  .btn:hover{background:var(--accent-strong)}
  .err{background:#fdecef;border:1px solid #f6c9d2;color:var(--danger);font-size:12.5px;
    padding:10px 12px;border-radius:10px;margin-bottom:16px}
  .foot{margin-top:18px;text-align:center;font-size:11.5px;color:var(--ink-soft)}
</style>
</head>
<body>
  <div class="card">
    <div class="brand">
      <div class="logo">KB</div>
      <div><b>K-Beauty Bliss</b><small>Store administration</small></div>
    </div>
    <h1>Welcome back</h1>
    <p class="sub">Sign in to manage your store.</p>

    @if ($errors->any())
      <div class="err">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('admin.login.post') }}">
      @csrf
      <div class="fld">
        <label for="email">Email</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
      </div>
      <div class="fld">
        <label for="password">Password</label>
        <input id="password" type="password" name="password" required autocomplete="current-password">
      </div>
      <div class="row">
        <label><input type="checkbox" name="remember"> Remember me</label>
      </div>
      <button class="btn" type="submit">Sign in</button>
    </form>

    <div class="foot">Protected area · authorised staff only</div>
  </div>
</body>
</html>
