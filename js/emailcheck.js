$(document).ready(function() {
    $('#email').on('keyup', function() {
        var email = $(this).val().trim();

        if (email.length === 0) {
            $('#email_val').text('');
            $('button[type="submit"]').prop('disabled', false);
            return;
        }

        $.ajax({
            url: 'check_email.php',
            type: 'GET',
            data: { email: email },
            success: function(response) {
                try {
                    var data = typeof response === 'object' ? response : JSON.parse(response);

                    if (data && data.exists === true) {
                        $('#email_val').text('This email is already taken!');
                        $('button[type="submit"]').prop('disabled', true);
                    } else {
                        $('#email_val').text('');
                        $('button[type="submit"]').prop('disabled', false);
                    }
                } catch (e) {
                    console.error('Error parsing email response:', e);
                    $('#email_val').text('');
                    $('button[type="submit"]').prop('disabled', false);
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX request failed for email check:', error);
                $('#email_val').text('');
                $('button[type="submit"]').prop('disabled', false);
            }
        });
    });
});

