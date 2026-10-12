{{-- Health details fields. Pass $record. --}}
<div class="row g-2">
    <div class="col-md-3"><label class="form-label">Blood group</label><select name="blood_group" class="form-select"><option value="">Not known</option>@foreach (\App\Support\Lookups::options('blood_group') as $k => $l)<option value="{{ $k }}" @selected($record->blood_group === $k)>{{ $l }}</option>@endforeach</select></div>
    <div class="col-md-3"><label class="form-label">Genotype</label><select name="genotype" class="form-select"><option value="">Not known</option>@foreach (\App\Support\Lookups::options('genotype') as $k => $l)<option value="{{ $k }}" @selected($record->genotype === $k)>{{ $l }}</option>@endforeach</select></div>
    <div class="col-md-6"><label class="form-label">Allergies</label><input name="allergies" value="{{ $record->allergies }}" class="form-control" placeholder="Medicines, foods or anything else"></div>
    <div class="col-md-6"><label class="form-label">Long-term conditions</label><textarea name="conditions" rows="2" class="form-control" placeholder="For example asthma, sickle cell, diabetes, epilepsy">{{ $record->conditions }}</textarea></div>
    <div class="col-md-6"><label class="form-label">Regular medication</label><textarea name="medications" rows="2" class="form-control">{{ $record->medications }}</textarea></div>
    <div class="col-12"><label class="form-label">Disabilities or support needed</label><input name="disabilities" value="{{ $record->disabilities }}" class="form-control"></div>
    <div class="col-md-5"><label class="form-label">Emergency contact</label><input name="emergency_name" value="{{ $record->emergency_name }}" class="form-control"></div>
    <div class="col-md-4"><label class="form-label">Their phone</label><input name="emergency_phone" value="{{ $record->emergency_phone }}" class="form-control"></div>
    <div class="col-md-3"><label class="form-label">Relationship</label><input name="emergency_relationship" value="{{ $record->emergency_relationship }}" class="form-control"></div>
</div>
