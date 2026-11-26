function Notify(type, res, msg = null) {
    let message = msg;

    if (!message) {
        if (res && res.responseJSON && res.responseJSON.message) {
            message = res.responseJSON.message;
        } else if (res && res.message) {
            message = res.message;
        } else if (res && res.responseText) {
            message = res.responseText;
        }
    }

    message = message || 'Something went wrong. Please try again.';

    switch (type) {
        case "error":
            toastr.error(message, 'Error', {
                closeButton: true,
                progressBar: true,
                timeOut: 5000,
                positionClass: 'toast-top-right',
            });
            break;
        case "success":
            toastr.success(message, 'Success', {
                closeButton: true,
                progressBar: true,
                timeOut: 4000,
                positionClass: 'toast-top-right',
            });
            break;
        case "warning":
            toastr.warning(message, 'Warning', {
                closeButton: true,
                progressBar: true,
                timeOut: 5000,
                positionClass: 'toast-top-right',
            });
            break;
        default:
            toastr.info(message, 'Info', {
                closeButton: true,
                progressBar: true,
                timeOut: 4000,
                positionClass: 'toast-top-right',
            });
    }
}
