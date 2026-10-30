<?php

namespace App\Modules\Administration\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\AuditLog;
use App\Services\Access\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SystemController extends Controller
{
    public function auditLogs(Request $request): View
    {
        $this->authorize('audit.view');

        return view('admin.system.audit', [
            'logs' => AuditLog::with('user')
                ->when($request->query('action'), fn ($q, $a) => $q->where('action', 'like', "$a%"))
                ->when($request->query('user_id'), fn ($q, $id) => $q->where('user_id', $id))
                ->latest('id')->paginate(50)->withQueryString(),
        ]);
    }

    public function failedJobs(): View
    {
        $this->authorize('system.monitor');

        return view('admin.system.jobs', [
            'jobs' => DB::table('failed_jobs')->latest('failed_at')->limit(50)->get(['id', 'uuid', 'queue', 'exception', 'failed_at']),
        ]);
    }

    public function announcements(): View
    {
        $this->authorize('announcements.manage');

        return view('admin.system.announcements', ['announcements' => Announcement::latest()->paginate(20)]);
    }

    public function storeAnnouncement(Request $request, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('announcements.manage');
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000'],
            'level' => ['required', Rule::in(['info', 'warning', 'critical'])],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);
        $announcement = Announcement::create($data + ['published_at' => now(), 'created_by' => $request->user()->id]);
        $audit->log('admin.announcement_published', $request->user(), $announcement);

        return back()->with('status', 'Announcement published.');
    }

    public function destroyAnnouncement(Request $request, Announcement $announcement, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('announcements.manage');
        $announcement->delete();
        $audit->log('admin.announcement_deleted', $request->user(), null, ['id' => $announcement->id]);

        return back()->with('status', 'Announcement removed.');
    }
}
