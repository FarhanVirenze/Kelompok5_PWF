<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\Vote;
use App\Models\Report;
use App\Models\FollowUp;
use App\Models\Comment;
use App\Models\KategoriUmum;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

class ReportSuperadminController extends Controller
{
    /**
     * Menampilkan semua laporan milik user (atau semua jika tidak login).
     */
    public function index()
    {
        if (auth()->check()) {
            $user = auth()->user();

            if ($user->role === 'admin') {
                // Ambil ID kategori yang ditugaskan ke admin
                $kategoriIds = $user->kategori->pluck('id')->toArray();

                // Ambil hanya laporan sesuai kategori admin
                $reports = Report::whereIn('kategori_id', $kategoriIds)
                    ->latest()
                    ->get(['id', 'judul', 'isi', 'nama_pengadu', 'kategori_id', 'status', 'created_at']);
            } elseif ($user->role === 'superadmin') {
                // Superadmin bisa melihat semua aduan
                $reports = Report::latest()
                    ->get(['id', 'judul', 'isi', 'nama_pengadu', 'kategori_id', 'status', 'created_at']);
            } else {
                // User biasa, hanya lihat aduan sendiri
                $reports = Report::where('user_id', $user->id_user)
                    ->latest()
                    ->get(['id', 'judul', 'isi', 'nama_pengadu', 'kategori_id', 'status', 'created_at']);
            }
        } else {
            // Pengunjung (belum login), tampilkan semua
            $reports = Report::latest()
                ->get(['id', 'judul', 'isi', 'nama_pengadu', 'kategori_id', 'status', 'created_at']);
        }

        return view('superadmin.welcome', compact('reports'));
    }

    /**
     * Tampilkan form pembuatan laporan.
     */
    public function create()
    {
        return view('user.aduan.create');
    }

    /**
     * Simpan laporan baru.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'judul' => 'required|string|min:20|max:150',
            'isi' => 'required|string|min:120|max:1000',
            'kategori_id' => 'required|exists:kategori_umum,id',
            'wilayah_id' => 'required|exists:wilayah_umum,id',
            'file' => 'required|array|max:3',
            'file.*' => 'file|mimes:jpg,jpeg,png,pdf,doc,docx,xls,xlsx,zip|max:10240',
            'lokasi' => 'required|string|max:255',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'is_anonim' => 'nullable|boolean',
        ]);

        // Simpan file
        if ($request->hasFile('file')) {
            $filePaths = [];
            foreach ($request->file('file') as $uploadedFile) {
                $filePaths[] = $uploadedFile->store('report_files', 'public');
            }
            $validated['file'] = $filePaths;
        }

        $validated['is_anonim'] = $request->boolean('is_anonim');
        $validated['status'] = Report::STATUS_DIAJUKAN;

        if (!$validated['is_anonim']) {
            $user = auth()->user();
            if (!$user)
                return back()->with('error', 'Pengguna tidak terautentikasi.');

            $validated['user_id'] = $user->id_user;
            $validated['nama_pengadu'] = $user->name;
            $validated['email_pengadu'] = $user->email;
            $validated['telepon_pengadu'] = $user->nomor_telepon ?? null;
            $validated['nik'] = $user->nik ?? null;
        } else {
            $validated['user_id'] = null;
            $validated['nama_pengadu'] = 'Anonim';
            $validated['email_pengadu'] = 'anonim@domain.com';
            $validated['telepon_pengadu'] = null;
            $validated['nik'] = null;
        }

        // Ambil admin_id dari kategori_umum
        $kategori = KategoriUmum::find($validated['kategori_id']);
        if ($kategori && $kategori->admin_id) {
            $validated['admin_id'] = $kategori->admin_id;
        } else {
            return back()->with('error', 'Kategori belum memiliki admin yang ditugaskan.');
        }

        try {
            Report::create($validated);
            return redirect()->route('superadmin.aduan.riwayat')->with('success', 'Laporan berhasil dikirim.');
        } catch (\Exception $e) {
            return back()->with('error', 'Gagal menyimpan laporan: ' . $e->getMessage());
        }
    }

    /**
     * Menampilkan detail laporan lengkap dengan komentar & follow up.
     */
    public function show($id)
    {
        $report = Report::with([
            'kategori',
            'wilayah',
            'user',
            'followUps.user',
            'comments.user'
        ])->findOrFail($id);

        // Validasi: Admin hanya bisa melihat laporan yang sesuai dengan kategori yang ia tangani
        if (auth()->check() && auth()->user()->role === 'admin') {
            if ($report->admin_id !== auth()->user()->id_user) {
                return response()->view('errors.akses-ditolak', [], 403);
            }
        }

        // Tambah jumlah view
        $sessionKey = 'report_viewed_' . $id;
        if (!session()->has($sessionKey)) {
            $report->increment('views');
            session()->put($sessionKey, true);
        }

        $followUps = $report->followUps->filter(fn($item) => $item->user && $item->user->role === 'admin');
        $comments = $report->comments;

        return view('superadmin.daftar-aduan.detail.index', compact('report', 'followUps', 'comments'));
    }

    public function like($id)
    {
        $user = auth()->user();
        if (!$user) {
            return redirect()->route('login')->with('error', 'Silakan login untuk memberikan like.');
        }

        $report = Report::findOrFail($id);
        $vote = Vote::where('user_id', $user->id_user)->where('report_id', $report->id)->first();

        if ($vote && $vote->vote_type === 'like') {
            $vote->delete();
            $report->decrement('likes');
            Session::forget('vote_report_' . $report->id); // hapus session
        } elseif ($vote && $vote->vote_type === 'dislike') {
            $vote->update(['vote_type' => 'like']);
            $report->increment('likes');
            $report->decrement('dislikes');
            Session::put('vote_report_' . $report->id, 'like');
        } else {
            Vote::create([
                'user_id' => $user->id_user,
                'report_id' => $report->id,
                'vote_type' => 'like',
            ]);
            $report->increment('likes');
            Session::put('vote_report_' . $report->id, 'like');
        }

        return back();
    }


    public function dislike($id)
    {
        $user = auth()->user();
        if (!$user) {
            return redirect()->route('login')->with('error', 'Silakan login untuk memberikan dislike.');
        }

        $report = Report::findOrFail($id);
        $vote = Vote::where('user_id', $user->id_user)->where('report_id', $report->id)->first();

        if ($vote && $vote->vote_type === 'dislike') {
            $vote->delete();
            if ($report->dislikes > 0) {
                $report->decrement('dislikes');
            }
            Session::forget('vote_report_' . $report->id); // hapus session
        } elseif ($vote && $vote->vote_type === 'like') {
            $vote->update(['vote_type' => 'dislike']);
            if ($report->likes > 0) {
                $report->decrement('likes');
            }
            $report->increment('dislikes');
            Session::put('vote_report_' . $report->id, 'dislike');
        } else {
            Vote::create([
                'user_id' => $user->id_user,
                'report_id' => $report->id,
                'vote_type' => 'dislike',
            ]);
            $report->increment('dislikes');
            Session::put('vote_report_' . $report->id, 'dislike');
        }

        return back();
    }

    /**
     * Simpan tindak lanjut dari admin.
     */
    public function storeFollowUp(Request $request, $reportId)
    {
        $request->validate([
            'pesan' => 'required|string',
            'file' => 'nullable|file|mimes:jpg,jpeg,png,pdf,doc,docx,xls,xlsx,zip|max:10240',
        ]);

        if (Auth::user()->role !== 'admin') {
            abort(403, 'Hanya admin yang dapat memberikan tindak lanjut.');
        }

        $followUp = new FollowUp([
            'report_id' => $reportId,
            'user_id' => Auth::user()->id_user,
            'pesan' => $request->pesan,
        ]);

        if ($request->hasFile('file')) {
            $followUp->file = $request->file('file')->store('followup_files', 'public');
        }

        $followUp->save();

        // Update status laporan menjadi 'Direspon' hanya jika status sebelumnya 'Dibaca'
        $report = Report::findOrFail($reportId);
        if ($report->status === Report::STATUS_DIBACA) {
            $report->status = Report::STATUS_DIRESPON;
            $report->save();
        }

        return back()->with('success', 'Tindak lanjut berhasil dikirim' .
            ($report->status === Report::STATUS_DIRESPON ? ', Status Aduan menjadi Direspon' : ''));
    }

    /**
     * Simpan komentar dari user.
     */
    public function storeComment(Request $request, $reportId)
    {
        $request->validate([
            'pesan' => 'required|string',
            'file' => 'nullable|file|mimes:jpg,jpeg,png,pdf,doc,docx,xls,xlsx,zip|max:10240',
        ]);

        if (Auth::user()->role !== 'user' && Auth::user()->role !== 'admin') {
            abort(403, 'Hanya user dan admin yang dapat mengirim komentar.');
        }

        $comment = new Comment([
            'report_id' => $reportId,
            'user_id' => Auth::user()->id_user,
            'pesan' => $request->pesan,
        ]);

        if ($request->hasFile('file')) {
            $comment->file = $request->file('file')->store('comment_files', 'public');
        }

        $comment->save();

        return back()->with('success', 'Komentar berhasil dikirim.');
    }

    public function getBadgeCounts($id)
    {
        $report = Report::withCount(['followUps', 'comments'])->findOrFail($id);

        return response()->json([
            'followUps' => $report->follow_ups_count,
            'comments' => $report->comments_count,
            'lampiran' => $report->file ? 1 : 0,
        ]);
    }

    /**
     * Hapus komentar.
     */
    public function deleteComment($id)
    {
        $comment = Comment::findOrFail($id);

        // Cek apakah user adalah pemilik komentar atau admin
        if (Auth::id() !== $comment->user_id && Auth::user()->role !== 'admin') {
            abort(403, 'Anda tidak diizinkan menghapus komentar ini.');
        }

        // Hapus file jika ada
        if ($comment->file && \Storage::disk('public')->exists($comment->file)) {
            \Storage::disk('public')->delete($comment->file);
        }

        $comment->delete();

        return back()->with('success', 'Komentar berhasil dihapus.');
    }

    /**
     * Hapus tindak lanjut (hanya admin yang bisa).
     */
    public function deleteFollowUp($reportId, $followUpId)
    {
        $followUp = FollowUp::findOrFail($followUpId);

        // Cek apakah user adalah admin
        if (Auth::user()->role !== 'admin') {
            abort(403, 'Anda tidak diizinkan menghapus tindak lanjut ini.');
        }

        // Hapus file jika ada
        if ($followUp->file && \Storage::disk('public')->exists($followUp->file)) {
            \Storage::disk('public')->delete($followUp->file);
        }

        // Hapus tindak lanjut
        $followUp->delete();

        // Jika tidak ada tindak lanjut lagi dan status sebelumnya adalah Direspon, ubah jadi Dibaca
        $report = Report::findOrFail($reportId);
        if ($report->followUps->isEmpty() && $report->status === Report::STATUS_DIRESPON) {
            $report->status = Report::STATUS_DIBACA;
            $report->save();

            return back()->with('success', 'Tindak lanjut berhasil dihapus, Status Aduan menjadi Dibaca');
        }

        return back()->with('success', 'Tindak lanjut berhasil dihapus');
    }

    public function lacak(Request $request)
    {
        $request->validate([
            'tracking_id' => 'required|string',
        ]);

        $report = Report::where('tracking_id', $request->tracking_id)->first();

        if (!$report) {
            return redirect()->back()->with('error', 'Nomor Tiket Aduan tidak ditemukan.');
        }

        // Redirect ke halaman detail
        return redirect()->route('superadmin.reports.show', ['id' => $report->id]);
    }

    public function riwayat()
    {
        if (!auth()->check()) {
            return redirect()->route('login')->with('error', 'Silakan login untuk melihat riwayat aduan.');
        }

        $user = auth()->user();

        // Tidak peduli role-nya apa, ambil aduan berdasarkan siapa yang mengajukan (user_id)
        $aduan = Report::where('user_id', $user->id_user)
            ->latest()
            ->get(['id', 'tracking_id', 'judul', 'status', 'created_at']);

        return view('superadmin.daftar-aduan.riwayat', compact('aduan'));
    }

    public function riwayatWbs()
    {
        // Misal: pakai model WbsReport, atau kamu bisa sesuaikan sendiri
        $aduan = Report::where('user_id', auth()->id()) // ganti kalau beda tabel
            ->where('kategori_id', 999) // contoh filter WBS
            ->latest()
            ->get(['id', 'tracking_id', 'judul', 'status', 'created_at']);

        return view('superadmin.daftar-aduan.riwayat-wbs', compact('aduan'));
    }
}
