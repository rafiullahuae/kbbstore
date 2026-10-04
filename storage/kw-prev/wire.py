# Applies the integrator's wiring lines to THIS worktree for the preview only.
# Reverted with: git checkout -- routes/web.php resources/views/admin/app.blade.php
import sys
root = '/home/user/lane-kw/'
def rep(p, old, new):
    s = open(root + p).read()
    assert s.count(old) == 1, (p, old)
    open(root + p, 'w').write(s.replace(old, new))
rep('routes/web.php', "        require __DIR__.'/seo-back-office.php';\n",
    "        require __DIR__.'/seo-back-office.php';\n        require __DIR__.'/seo-keywords-admin.php';\n")
rep('resources/views/admin/app.blade.php', "@include('admin.partials.seo-back-office')\n",
    "@include('admin.partials.seo-back-office')\n@include('admin.partials.seo-keywords-screen')\n")
rep('resources/views/admin/app.blade.php',
    "  {screen:'searchterms',label:'Search Terms',group:'Growth & Marketing',after:['pixels','meta','labels','newsletter'],icon:'<circle cx=\"11\" cy=\"11\" r=\"7\"/><path d=\"m21 21-4-4\"/><path d=\"M8 11h6\"/><path d=\"M11 8v6\"/>'}\n];",
    "  {screen:'searchterms',label:'Search Terms',group:'Growth & Marketing',after:['pixels','meta','labels','newsletter'],icon:'<circle cx=\"11\" cy=\"11\" r=\"7\"/><path d=\"m21 21-4-4\"/><path d=\"M8 11h6\"/><path d=\"M11 8v6\"/>'},\n  {screen:'seokeywords',label:'SEO Keywords',group:'Store',after:['seo','search'],icon:'<path d=\"M4 7h9M4 12h6M4 17h4\"/><circle cx=\"16.5\" cy=\"13.5\" r=\"4.5\"/><path d=\"m20 17 2 2\"/>'}\n];")
rep('resources/views/admin/app.blade.php', "'searchterms':['Growth & Marketing','Search Terms']};", "'searchterms':['Growth & Marketing','Search Terms'],'seokeywords':['Store','SEO Keywords']};")
rep('resources/views/admin/app.blade.php', "'pagewash','searchterms','emails',", "'pagewash','searchterms','seokeywords','emails',")
print('wired')
