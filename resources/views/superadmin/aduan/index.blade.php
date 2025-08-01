@extends('superadmin.layouts.app')

@section('title', 'Kelola Aduan Umum')

@section('content')
    <div class="container mt-4">
        <h2 class="mb-4 text-2xl font-semibold text-[#37474F]">Kelola Aduan Umum</h2>

        @if(session('success'))
            <div id="alert-success" class="alert alert-success">
                {{ session('success') }}
            </div>
        @elseif(session('error'))
            <div id="alert-error" class="alert alert-danger">
                {{ session('error') }}
            </div>
        @endif

        @if($reports->isEmpty())
            <div class="alert alert-info">Tidak ada aduan saat ini.</div>
        @else
            <div class="table-responsive">
                <table class="table table-striped table-bordered w-100">
                    <thead class="bg-gradient-to-r from-blue-700 to-blue-500 text-white">
                        <tr>
                            <th>Judul</th>
                            <th>Status</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($reports as $report)
                            <tr>
                                <td>{{ $report->judul }}</td>
                                <td>
                                    <span class="rounded-full px-2 py-1 text-xs font-semibold
                                        @if($report->status == 'Diajukan') bg-blue-200 text-blue-800
                                        @elseif($report->status == 'Dibaca') bg-teal-200 text-teal-800
                                        @elseif($report->status == 'Direspon') bg-yellow-200 text-yellow-800
                                        @elseif($report->status == 'Selesai') bg-green-200 text-green-800
                                        @endif">
                                        {{ $report->status }}
                                    </span>
                                </td>
                                <td>
                                    <div class="flex gap-2 flex-wrap">
                                        <button type="button"
                                            class="px-3 py-1 text-sm bg-yellow-400 hover:bg-yellow-500 rounded text-white"
                                            data-toggle="modal" data-target="#editAduanModal"
                                            data-id="{{ $report->id }}" data-judul="{{ $report->judul }}"
                                            data-status="{{ $report->status }}">
                                            Edit
                                        </button>

                                        <a href="{{ route('superadmin.reports.show', ['id' => $report->id]) }}"
                                            class="px-3 py-1 text-sm bg-blue-500 hover:bg-blue-600 rounded text-white">
                                            Lihat
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-6 d-flex justify-content-center">
                {{ $reports->links() }}
            </div>
        @endif

        <!-- Modal Edit Aduan -->
        <div class="modal fade" id="editAduanModal" tabindex="-1" role="dialog" aria-labelledby="editAduanModalLabel"
            aria-hidden="true">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <form method="POST" id="editAduanForm">
                        @csrf
                        @method('PUT')
                        <div class="modal-header">
                            <h5 class="modal-title">Edit Status Aduan</h5>
                            <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                        </div>
                        <div class="modal-body">
                            <div class="form-group">
                                <label for="editStatus">Status</label>
                                <select name="status" id="editStatus" class="form-control" required>
                                    <option value="Diajukan">Diajukan</option>
                                    <option value="Dibaca">Dibaca</option>
                                    <option value="Direspon">Direspon</option>
                                    <option value="Selesai">Selesai</option>
                                </select>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Tutup</button>
                            <button type="submit" class="btn btn-primary">Update</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        $('#editAduanModal').on('show.bs.modal', function (event) {
            const button = $(event.relatedTarget);
            const id = button.data('id');
            const status = button.data('status');

            const modal = $(this);
            modal.find('#editStatus').val(status);
            modal.find('#editAduanForm').attr('action', `/superadmin/kelola-aduan/${id}`);
        });

        @if(session('success') || session('error'))
            setTimeout(() => {
                $('#alert-success').fadeOut('slow');
                $('#alert-error').fadeOut('slow');
            }, 3000);
        @endif
    </script>
@endpush
