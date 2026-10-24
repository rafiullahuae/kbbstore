<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\RevenueReportController;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| Dashboard -> Revenue  (Lane REV)
|------------------------------------------------------------------------------
|
| Required from routes/web.php inside the admin-api group (auth:admin,
| NoStoreAdminApi). GET /admin-api/revenue-report?range=… answers the
| dashboard's Revenue card: this month, last 30 days, this year, a picked
| month (m:YYYY-MM), a picked year (y:YYYY) or two dates (c:FROM:TO). Guarded
| by analytics.view in AdminCapabilities. Read only. A clear_caches migration
| ships with it so the compiled route cache learns the path.
*/
Route::get('/revenue-report', RevenueReportController::class);
