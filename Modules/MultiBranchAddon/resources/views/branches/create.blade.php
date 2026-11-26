<div class="modal fade common-validation-modal" id="branches-create-modal">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h1 class="modal-title fs-5">{{ __('Create Branch') }}</h1>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="personal-info">
                    <form action="{{ route('multibranch.branches.store') }}" method="post" enctype="multipart/form-data"
                        class="ajaxform_instant_reload">
                        @csrf
                        <div class="row">
                            <div class="col-lg-6 mb-2">
                                <label>{{ __('Name') }}</label>
                                <input type="text" name="name" class="form-control" placeholder="{{ __('Enter name') }}" required>
                            </div>
                            <div class="col-lg-6 mb-2">
                                <label>{{ __('Phone') }}</label>
                                <input type="number" name="phone" class="form-control" placeholder="{{ __('Enter phone') }}">
                            </div>
                            <div class="col-lg-6 mb-2">
                                <label>{{ __('Email') }}</label>
                                <input type="email" name="email" class="form-control" placeholder="{{ __('Enter email') }}">
                            </div>
                            <div class="col-lg-6 mb-2">
                                <label>{{ __('Address') }}</label>
                                <input type="text" name="address" class="form-control" placeholder="{{ __('Enter address') }}">
                            </div>
                            <div class="col-lg-6 mb-2">
                                <label>{{ __('Opening Balance') }}</label>
                                <input type="number" name="branchOpeningBalance" class="form-control" placeholder="{{ __('Enter balance') }}">
                            </div>
                            <div class="col-lg-12">
                                <label>{{__('Description')}}</label>
                                <textarea name="description" class="form-control" placeholder="{{ __('Enter description') }}"></textarea>
                            </div>

                            {{-- §16: Tax and GST Configuration, its own named section
                                 so the tax settings are not mistaken for contact
                                 details. --}}
                            <div class="col-lg-12 mt-4">
                                <h6 class="mb-1">{{ __('Tax and GST Configuration') }}</h6>
                                @if (!empty($gst['notice']))
                                    {{-- Says what the shop type implies before anything
                                         is changed, so a deviation is deliberate. --}}
                                    <p class="text-muted small mb-2">{{ $gst['notice'] }}</p>
                                @endif
                                <hr class="mt-1 mb-3">
                            </div>

                            <div class="col-lg-6 mb-2">
                                <label>{{ __('GST Registered') }}</label>
                                <select name="gst_registered" class="form-control gst-registered-input">
                                    <option value="">{{ __('Not answered') }}</option>
                                    <option value="1">{{ __('Yes') }}</option>
                                    <option value="0">{{ __('No') }}</option>
                                </select>
                            </div>

                            <div class="col-lg-6 mb-2">
                                <label>{{ __('GST Pricing Method') }}</label>
                                <select name="default_vat_type" class="form-control">
                                    {{-- Blank inherits the shop type's default rather
                                         than forcing a choice that is already known. --}}
                                    <option value="">{{ __('Use the shop type default') }}</option>
                                    @foreach ($gst['methods'] as $method)
                                        <option value="{{ $method['value'] }}">{{ __($method['label']) }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="col-lg-6 mb-2">
                                <label>{{ __('GSTIN') }}</label>
                                <input type="text" name="gstin" class="form-control" maxlength="15"
                                    placeholder="{{ __('e.g. 29ABCDE1234F1Z5') }}">
                            </div>

                            <div class="col-lg-6 mb-2">
                                <label>{{ __('GST State') }}</label>
                                <select name="gst_state" class="form-control">
                                    <option value="">{{ __('Select state') }}</option>
                                    @foreach ($gst['states'] as $state)
                                        <option value="{{ $state['name'] }}">{{ $state['code'] }} — {{ $state['name'] }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="col-lg-6 mb-2">
                                <label>{{ __('Place of Supply') }}</label>
                                <input type="text" name="place_of_supply" class="form-control"
                                    placeholder="{{ __('Defaults to the GST state') }}">
                            </div>

                            <div class="col-lg-6 mb-2">
                                <label>{{ __('Effective From') }}</label>
                                <input type="date" name="gst_effective_from" class="form-control">
                            </div>

                            <div class="col-lg-6 mb-2">
                                <label>{{ __('Allow GST Override') }}</label>
                                <select name="allow_gst_override" class="form-control">
                                    <option value="1">{{ __('Yes') }}</option>
                                    <option value="0">{{ __('No — only an administrator may change it') }}</option>
                                </select>
                            </div>

                            <div class="col-lg-6 mb-2">
                                <label>{{ __('Reason for Override') }}</label>
                                <input type="text" name="gst_change_reason" class="form-control"
                                    placeholder="{{ __('Required when deviating from the shop type') }}">
                            </div>
                         </div>
                        <div class="col-lg-12">
                            <div class="button-group text-center mt-5">
                                <button type="reset" class="theme-btn border-btn m-2">{{ __('Reset') }}</button>
                                @usercan('branches.create')
                                <button class="theme-btn m-2 submit-btn">{{ __('Save') }}</button>
                                @endusercan
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
