$(document).ready(function() {
    $('#username').on('keyup', function() {
        var username = $(this).val().trim();

        if (username.length === 0) {
            $('#username_val').text('');
            $('button[type="submit"]').prop('disabled', false);
            return;
        }

        $.ajax({
            url: 'check_username.php',
            type: 'GET',
            data: { username: username },
            success: function(response) {
                try {
                    var data = typeof response === 'object' ? response : JSON.parse(response);

                    if (data && data.exists === true) {
                        $('#username_val').text('This username is already taken!');
                        $('button[type="submit"]').prop('disabled', true);
                    } else {
                        $('#username_val').text('');
                        $('button[type="submit"]').prop('disabled', false);
                    }
                } catch (e) {
                    console.error('Error parsing username response:', e);
                    $('#username_val').text('');
                    $('button[type="submit"]').prop('disabled', false);
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX request failed for username check:', error);
                $('#username_val').text('');
                $('button[type="submit"]').prop('disabled', false);
            }
        });
    });
});


