{{--
    Pemilih cakupan: Cabang Dinas, lalu sekolah di dalamnya.

    DUA TINGKAT, BUKAN SATU

    Seorang Superadmin bercakupan 2.085 sekolah. Satu dropdown berisi
    sebanyak itu secara teknis benar tetapi tidak dapat dipakai siapa pun —
    menggulirnya lebih lama daripada mengetik NPSN di kotak pencarian.
    Dengan memilih wilayah lebih dulu, daftar sekolahnya turun ke puluhan
    sampai ratusan.

    Pemilih wilayah tidak dirender untuk akun tingkat sekolah, dan andai pun
    di-POST tetap ditolak App\Support\Tenant.

    Variabel dari controller : $schools, $schoolId, $allowAll
    Variabel dari composer   : $capdisList, $capdisId
--}}
@php
    $capdisList = $capdisList ?? collect();
    $punyaCapdis = $capdisList->count() > 1;
@endphp

@if ($punyaCapdis || ($schools ?? collect())->isNotEmpty())
    <form method="GET" class="d-flex align-items-center gap-2 flex-wrap">
        {{-- Parameter lain pada URL dipertahankan agar filter tidak hilang
             saat pengguna berganti wilayah atau sekolah. --}}
        @foreach (request()->except(['school_id', 'capdis_id', 'page']) as $key => $value)
            @if (! is_array($value))
                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
            @endif
        @endforeach

        @if ($punyaCapdis)
            <span class="text-muted fs-8 text-nowrap">Wilayah</span>
            <select name="capdis_id" class="form-select form-select-sm w-200px"
                    onchange="this.form.school_id.value = 'all'; this.form.submit()">
                <option value="all" {{ $capdisId ? '' : 'selected' }}>— Seluruh Capdis —</option>
                @foreach ($capdisList as $c)
                    <option value="{{ $c->id }}" {{ $capdisId === $c->id ? 'selected' : '' }}>
                        {{ $c->name }}
                    </option>
                @endforeach
            </select>
        @endif

        <span class="text-muted fs-8 text-nowrap">Sekolah</span>
        <select name="school_id" class="form-select form-select-sm w-250px" onchange="this.form.submit()">
            @if ($allowAll ?? true)
                <option value="all" {{ $schoolId ? '' : 'selected' }}>
                    {{ $capdisId ? '— Seluruh sekolah wilayah ini —' : '— Seluruh Provinsi —' }}
                </option>
            @else
                <option value="">— Pilih sekolah —</option>
            @endif
            @foreach ($schools as $s)
                <option value="{{ $s->id }}" {{ $schoolId === $s->id ? 'selected' : '' }}>
                    {{ $s->name }}@if (! empty($s->npsn)) ({{ $s->npsn }}) @endif
                </option>
            @endforeach
        </select>

        <span class="text-muted fs-9 text-nowrap">{{ $schools->count() }} sekolah</span>
    </form>
@endif
