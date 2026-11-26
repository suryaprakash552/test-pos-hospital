@foreach($manufacturers as $manufacturer)
    <tr>
        <td class="w-60 checkbox">
            <input type="checkbox" name="ids[]" class="delete-checkbox-item  multi-delete" value="{{ $manufacturer->id }}">
        </td>
        <td>{{ ($manufacturers->currentPage() - 1) * $manufacturers->perPage() + $loop->iteration }}</td>
        <td>
            <img src="{{ asset($manufacturer->icon ?? 'assets/images/logo/upload2.jpg') }}" alt="Img" class="table-product-img">
        </td>

        <td class="text-start">{{ $manufacturer->manufacturerName }}</td>
        <td class="text-start">{{ Str::limit($manufacturer->description, 20, '...') }}</td>
        <td>
            <label class="switch">
                <input type="checkbox" {{ $manufacturer->status == 1 ? 'checked' : '' }} class="status" data-url="{{ route('business.manufacturers.status', $manufacturer->id) }}">
                <span class="slider round"></span>
            </label>
        </td>
        <td class="print-d-none">
            <div class="dropdown table-action">
                <button type="button" data-bs-toggle="dropdown">
                    <i class="far fa-ellipsis-v"></i>
                </button>
                <ul class="dropdown-menu">
                    <li>
                        <a href="#manufacturer-edit-modal" data-bs-toggle="modal" class="manufacturer-edit-btn"
                        data-url="{{ route('business.manufacturers.update', $manufacturer->id) }}"
                        data-manufacturers-name="{{ $manufacturer->manufacturerName }}"
                        data-manufacturers-icon="{{ asset($manufacturer->icon) }}"
                        data-manufacturers-description="{{ $manufacturer->description }}"><i class="fal fa-pencil-alt"></i>{{__('Edit')}}</a>
                    </li>
                    <li>
                        <a href="{{ route('business.manufacturers.destroy', $manufacturer->id) }}" class="confirm-action" data-method="DELETE">
                            <i class="fal fa-trash-alt"></i>
                            {{ __('Delete') }}
                        </a>
                    </li>
                </ul>
            </div>
        </td>
    </tr>
@endforeach
