<script src="{{ asset('assets/js/jquery-3.7.1.min.js') }}"></script>
<script src="{{ asset('assets/js/bootstrap.bundle.min.js') }}"></script>
<script src="{{ asset('assets/js/theme.js') }}"></script>
<script src="{{ asset('assets/js/math.js') }}"></script>
{{-- jquery confirm --}}
<script src="{{asset('assets/plugins/jquery-confirm/jquery-confirm.min.js')}}"></script>
{{-- jquery validation --}}
<script src="{{asset('assets/plugins/jquery-validation/jquery.validate.min.js')}}"></script>
{{-- Custom --}}
<script src="{{ asset('assets/plugins/validation-setup/validation-setup.js') }}"></script>
<script src="{{ asset('assets/plugins/custom/notification.js') }}"></script>
<script src="{{ asset('assets/plugins/custom/form.js') }}"></script>
{{-- Status --}}
<script src="{{ asset('assets/js/custom-ajax.js') }}"></script>
<script src="{{ asset('assets/js/slick.min.js') }}"></script>
{{-- Toaster --}}
<script src="{{ asset('assets/js/toastr.min.js') }}"></script>
<script src="{{ asset('assets/js/custom/custom.js') }}"></script>

@stack('js')

@stack('modal-view')

<script>
    toastr.options = {
        closeButton: true,
        progressBar: true,
        positionClass: 'toast-top-right',
        timeOut: '5000',
        extendedTimeOut: '1000',
        preventDuplicates: true,
        newestOnTop: true,
    };
</script>

{{-- Toaster Message --}}
@if(Session::has('message'))
    <script>
        Notify('success', null, "{{ Session::get('message') }}");
    </script>
@endif
@if(Session::has('error'))
    <script>
        Notify('error', null, "{{ Session::get('error') }}");
    </script>
@endif
@if($errors->any())
<script>
    Notify('warning', null, 'Please review the highlighted fields and try again.');
</script>
@endif
