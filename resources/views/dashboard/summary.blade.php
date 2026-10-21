{{-- Loaded into the dashboard after it shows (DashboardController::summary). --}}
<div id="uv-dash-stats">
            <div class="row">
                @foreach ($stats as $s)
                    @can($s['can'])
                        <div class="col-xl col-md-4 col-6">
                            <a href="{{ route($s['route']) }}" class="card card-animate text-decoration-none">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <p class="text-uppercase fw-medium text-muted fs-12 mb-2">{{ $s['label'] }}</p>
                                            <h3 class="mb-0 text-body">{{ number_format($s['value']) }}</h3>
                                        </div>
                                        <span class="avatar-sm"><span class="avatar-title bg-primary-subtle text-primary rounded fs-3"><i class="{{ $s['icon'] }}"></i></span></span>
                                    </div>
                                </div>
                            </a>
                        </div>
                    @endcan
                @endforeach
            </div>

</div>
@php
        $steps = [
                ['Create an academic session and its semesters', \App\Models\AcademicSession::exists(), 'academics.sessions.index', 'View academic sessions'],
                ['Add faculties', \App\Models\Faculty::exists(), 'academics.faculties.index', 'View faculties'],
                ['Add departments', \App\Models\Department::exists(), 'academics.departments.index', 'View departments'],
                ['Add programmes', \App\Models\Programme::exists(), 'academics.programmes.index', 'View programmes'],
                ['Add courses with credit units', \App\Models\Course::exists(), 'academics.courses.index', 'View courses'],
                ['Build each programme\'s curriculum', \App\Models\ProgrammeCourse::exists(), 'academics.curriculum.index', 'View programme curriculum'],
                ['Record applicants and admit them as students', \App\Models\Student::exists(), 'applicants.index', 'View applicants'],
                ['Create bill items (tuition, hostel …)', \App\Models\BillItem::exists(), 'bills.items.index', 'View bill items'],
                ['Set school bills per programme and level', \App\Models\Bill::exists(), 'bills.structure.index', 'View school bills'],
            ];
@endphp
<div id="uv-dash-setup"><div class="card-body">
                    <ul class="list-unstyled mb-0">
                        @foreach ($steps as [$label, $done, $route, $perm])
                            <li class="d-flex align-items-center gap-2 py-1">
                                <i class="{{ $done ? 'ri-checkbox-circle-fill text-success' : 'ri-checkbox-blank-circle-line text-muted' }} fs-5"></i>
                                @can($perm)
                                    <a href="{{ route($route) }}" class="{{ $done ? 'text-muted' : 'fw-medium' }}">{{ $label }}</a>
                                @else
                                    <span class="{{ $done ? 'text-muted' : '' }}">{{ $label }}</span>
                                @endcan
                            </li>
                        @endforeach
                    </ul>
                </div></div>
