<script src="{{ asset('assets/web/js/jquery-3.6.0.min.js') }}"></script>
<script src="{{ asset('assets/web/js/bootstrap.bundle.min.js') }}"></script>
<script src="{{ asset('assets/web/js/swiper-bundle.min.js') }}"></script>
<script src="{{ asset('assets/web/js/lity.min.js') }}"></script>
<script src="{{ asset('assets/web/js/slick.min.js') }}"></script>
<script src="{{ asset('assets/web/js/type.js') }}"></script>

<script src="{{ asset('assets/plugins/custom/notification.js') }}"></script>
<script src="{{ asset('assets/plugins/validation-setup/validation-setup.js') }}"></script>
<script src="{{ asset('assets/js/toastr.min.js') }}"></script>
<script src="{{ asset('assets/plugins/jquery-confirm/jquery-confirm.min.js')}}"></script>
<script src="{{ asset('assets/plugins/jquery-validation/jquery.validate.min.js') }}"></script>
<script src="{{ asset('assets/plugins/custom/form.js') }}?v={{ time() }}"></script>
<script src="{{ asset('assets/js/custom-ajax.js') }}?v={{ time() }}"></script>
<script src="{{ asset('assets/web/js/custom.js') }}?v={{ time() }}"></script>
<script src="{{ asset('assets/js/auth.js') }}?v={{ time() }}"></script>

@stack('js')

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

@if(Session::has('warning'))
    <script>
        Notify('warning', null, "{{ Session::get('warning') }}");
    </script>
@endif
