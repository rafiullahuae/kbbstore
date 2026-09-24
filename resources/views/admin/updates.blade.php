{{--
    Standalone page — it does not extend an admin layout, because the admin is a
    single-file SPA with no Blade layout to inherit from. Self-contained means it
    also still renders if the admin app's own assets fail, which is exactly when
    you are most likely to need this screen.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Updates · KBB Admin</title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#0f1720;color:#dbe4ee;font:14px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
.wrap{max-width:900px;margin:0 auto;padding:28px 18px 70px}
a{color:#5fb3f5}
h1{font-size:22px;margin:0 0 4px}
h2{font-size:15px;margin:0 0 10px}
.muted{color:#8b9bad}
.small{font-size:12px}
.card{background:#16212d;border:1px solid #24323f;border-radius:12px;padding:18px 20px;margin:16px 0}
.card.pending{border-color:#3d7d46}
.warn{background:#3a2f14;border:1px solid #6b5620;color:#e8d59a;border-radius:10px;padding:12px 15px;margin:14px 0}
.ok{background:#14321f;border:1px solid #2c6b3f;color:#a7e0bb;border-radius:10px;padding:12px 15px;margin:14px 0}
.err{background:#361a1a;border:1px solid #7a2f2f;color:#f0b4b4;border-radius:10px;padding:12px 15px;margin:14px 0}
.err ul{margin:8px 0 0 18px;padding:0}
.safety{background:#12212c;border-left:3px solid #3d7d46;padding:10px 14px;margin:14px 0;font-size:13px;color:#9fc4a9}
input[type=file],input[type=password]{background:#0f1720;border:1px solid #2a3947;color:#dbe4ee;border-radius:8px;padding:9px 11px;font-size:13px}
input[type=password]{min-width:220px}
label{display:block;margin:14px 0 6px;font-size:12px;color:#8b9bad}
.btn{background:#24323f;border:1px solid #35485a;color:#dbe4ee;border-radius:8px;padding:9px 16px;font-size:13px;font-weight:600;cursor:pointer;margin-left:8px}
.btn.primary{background:#2c6b3f;border-color:#3d8f55;color:#fff}
.btn.small{padding:5px 10px;font-size:12px}
table{width:100%;border-collapse:collapse;margin-top:10px;font-size:13px}
th{text-align:left;color:#8b9bad;font-size:11px;text-transform:uppercase;letter-spacing:.05em;padding:8px 10px;border-bottom:1px solid #24323f}
td{padding:10px;border-bottom:1px solid #1c2836;vertical-align:top}
.status{font-size:11px;font-weight:700;padding:2px 8px;border-radius:99px;text-transform:uppercase}
.status.applied{background:#14321f;color:#7fd6a0}
.status.rolled_back{background:#3a2f14;color:#e8c67a}
.status.failed{background:#361a1a;color:#f0a0a0}
.status.running{background:#1b2a3a;color:#8fc0f0}
.files{max-height:280px;overflow:auto;margin:10px 0 0;padding:10px;background:#0f1720;border-radius:8px;list-style:none;font-family:ui-monospace,monospace;font-size:12px}
.files li{padding:2px 0}
.tag{display:inline-block;min-width:56px;font-size:10px;font-weight:700;text-transform:uppercase;color:#0f1720;border-radius:4px;text-align:center;margin-right:8px}
.tag.replace{background:#e8c67a}
.tag.add{background:#7fd6a0}
summary{cursor:pointer;color:#5fb3f5;margin-top:10px}
code{background:#0f1720;padding:1px 5px;border-radius:4px;font-size:12px}
</style>
</head>
<body>
<div class="wrap">
    <p class="small"><a href="{{ url('/admin') }}">&larr; Back to admin</a></p>

    <h1>Updates</h1>
    <p class="muted">Current version <b>{{ $currentVersion }}</b></p>

    <p class="muted">Package signing: <b>{{ $signing['label'] }}</b>@if ($signing['keys']) · trusted key {{ implode(', ', $signing['fingerprints']) }}@endif</p>

    @if ($signing['emergency'])
        <div class="warn">
            <b>Emergency mode.</b> <code>storage/app/{{ \App\Services\Update\SigningMode::HATCH_FILE }}</code>
            exists, so unsigned packages are accepted whatever this site is configured to require.
            Delete that file as soon as the package that needed it has been applied.
        </div>
    @elseif ($signing['mode'] !== 'required' && ! $signedMode)
        <div class="warn">
            <b>Unsigned packages are accepted.</b> Any zip that passes the file checks will install.
            @if ($signing['keys'])
                A package that <em>is</em> signed is verified against this site's trusted key and refused if it
                does not match; a package with no signature is still accepted. Set
                <code>KBB_UPDATE_SIGNING=required</code> once a signed package has applied successfully.
            @else
                This site holds no signing key yet. See <code>docs/PACKAGE-SIGNING.md</code>.
            @endif
        </div>
    @endif

    @if (session('kbb_update_message'))
        <div class="ok">{{ session('kbb_update_message') }}</div>
    @endif

    @if (session('kbb_update_errors'))
        <div class="err">
            <b>Nothing was changed.</b>
            <ul>
                @foreach (session('kbb_update_errors') as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if (! $pending)
        <div class="card">
            <h2>Upload an update</h2>
            <p class="muted">
                The package is checked first — file list, checksums, permitted paths.
                Nothing is written until you confirm on the next screen.
            </p>
            <form method="post" action="{{ route('admin.updates.upload') }}" enctype="multipart/form-data">
                @csrf
                <input type="file" name="package" accept=".zip" required>
                <button type="submit" class="btn">Check package</button>
            </form>
        </div>
    @else
        <div class="card pending">
            <h2>Ready to apply — {{ $pending['name'] }} {{ $pending['version'] }}</h2>

            @if ($pending['notes'])
                <p>{{ $pending['notes'] }}</p>
            @endif

            <p class="muted">
                <b>{{ count($pending['changes']) }} files</b> will change.
                @if ($pending['migrations'])
                    This update also changes the database. A full dump is taken first.
                @endif
            </p>

            <details>
                <summary>Show every file</summary>
                <ul class="files">
                    @foreach ($pending['changes'] as $change)
                        <li><span class="tag {{ $change['action'] }}">{{ $change['action'] }}</span>{{ $change['path'] }}</li>
                    @endforeach
                </ul>
            </details>

            <div class="safety">
                Before anything is written, every file being replaced is backed up.
                Afterwards the site is checked over HTTP — if it does not respond correctly,
                the update is undone automatically.
            </div>

            <form method="post" action="{{ route('admin.updates.apply') }}">
                @csrf
                <label>Confirm with your admin password</label>
                <input type="password" name="password" required autocomplete="current-password">
                <button type="submit" class="btn primary">Apply update</button>
            </form>

            <form method="post" action="{{ route('admin.updates.cancel') }}" style="margin-top:10px" onsubmit="return confirm('Discard this package? Nothing on the site has changed yet.')">
                @csrf
                <button type="submit" class="btn">Cancel and discard this package</button>
            </form>
        </div>
    @endif


    <h2 style="margin-top:30px">Admin address</h2>
    <div class="card">
        <p class="muted" style="margin-top:0">
            The back office currently answers at <code>/{{ $adminPath ?? 'admin' }}</code>.
            Changing it makes the old address return 404 — not a redirect, which would
            simply announce where it moved to.
        </p>

        @if ($adminPathLocked ?? false)
            <div class="warn">
                <b>Locked by .env.</b> <code>KBB_ADMIN_PATH</code> is set there, and it always wins.
                Remove that line to manage the address from here.
            </div>
        @else
            <div class="safety">
                A secret address stops automated scanners — that is most of the background
                noise. It is not a security control: anyone who sees one admin link has it.
                Your password, the rate limiter and the IP allow-list are the real protection.
                <br><br>
                <b>If you ever lock yourself out</b>, set <code>KBB_ADMIN_PATH</code> in
                <code>.env</code>. It overrides whatever is stored here, so there is always a
                way back in from outside the admin.
            </div>

            <form method="post" action="{{ url('/' . ($adminPath ?? 'admin') . '/admin-path') }}">
                @csrf
                <label>New admin address</label>
                <input type="text" name="admin_path" value="{{ $adminPath ?? 'admin' }}" required
                       pattern="[A-Za-z0-9][A-Za-z0-9\-_]{2,40}"
                       style="min-width:240px;background:#0f1720;border:1px solid #2a3947;color:#dbe4ee;border-radius:8px;padding:9px 11px">
                <label>Confirm with your admin password</label>
                <input type="password" name="password" required autocomplete="current-password">
                <button type="submit" class="btn primary">Change address</button>
            </form>
        @endif
    </div>

    <h2 style="margin-top:30px">History</h2>
    <table>
        <thead><tr><th>Version</th><th>Status</th><th>Files</th><th>When</th><th></th></tr></thead>
        <tbody>
        @forelse ($releases as $release)
            <tr>
                <td><b>{{ $release->version }}</b><br><span class="muted small">{{ $release->name }}</span>
                    @if ($release->superseded_by)
                        <br><span class="muted small" style="color:#b45309">⚠ Superseded by {{ $release->superseded_by }} — see that version's notes</span>
                    @endif
                </td>
                <td><span class="status {{ $release->status }}">{{ str_replace('_', ' ', $release->status) }}</span>
                    @if ($release->error)<br><span class="muted small">{{ $release->error }}</span>@endif
                </td>
                <td>{{ $release->file_count }}</td>
                <td class="muted small">{{ $release->created_at?->diffForHumans() }}</td>
                <td>
                    @if ($release->canRollBack())
                        <form method="post" action="{{ route('admin.updates.rollback', $release) }}" onsubmit="return confirm('Roll back to the state before this update?')">
                            @csrf
                            <input type="password" name="password" placeholder="password" required style="min-width:130px">
                            <button type="submit" class="btn small">Roll back</button>
                        </form>
                    @endif
                    @if ($release->archive_path)
                        <a href="{{ route('admin.updates.download', $release) }}" class="btn small" style="margin-top:6px;display:inline-block">Download package</a>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="5" class="muted">No updates yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
</body>
</html>
