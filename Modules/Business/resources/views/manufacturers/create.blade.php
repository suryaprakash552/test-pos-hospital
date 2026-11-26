
<div class="modal fade" id="manufacturer-create-modal">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h1 class="modal-title fs-5">{{ __('Create Manufacturer') }}</h1>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="personal-info">
                    <form action="{{ route('business.manufacturers.store') }}" method="post" enctype="multipart/form-data"
                        class="ajaxform_instant_reload">
                        @csrf

                        <div class="row">
                            <div class="col-lg-12 mb-2">
                                <label>{{ __('Manufacturer Name') }}</label>
                                <input type="text" name="manufacturerName" required class="form-control" placeholder="{{ __('Enter Manufacturer Name') }}">
                            </div>

                            <div class="col-lg-12">
                                <label>{{ __('Icon') }}</label>
                                <div class="border rounded upload-img-container">
                                    <label class="upload-v4">
                                        <div class="img-wrp">
                                            <img src="{{ asset('assets/images/icons/upload-icon.svg') }}" alt="Banufacturer" id="manufacturer-img">
                                        </div>
                                        <input type="file" name="icon" class="d-none" onchange="document.getElementById('manufacturer-img').src = window.URL.createObjectURL(this.files[0])" accept="image/*">
                                    </label>
                                </div>
                            </div>

                            <div class="col-lg-12 mt-1">
                                <label>{{__('Description')}}</label>
                                <textarea name="description" class="form-control" placeholder="{{ __('Enter Description') }}"></textarea>
                            </div>
                         </div>
                        <div class="col-lg-12">
                            <div class="button-group text-center mt-5">
                                <button type="reset" class="theme-btn border-btn m-2">{{ __('Reset') }}</button>
                                <button class="theme-btn m-2 submit-btn">{{ __('Save') }}</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
