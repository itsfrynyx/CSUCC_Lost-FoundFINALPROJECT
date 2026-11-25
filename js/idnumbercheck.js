// Global flag to track ID existence status
var idNumberExists = false;

$(document).ready(function () {
    $('#idnumber').on('keyup', function () {
        var idnumber = $(this).val().trim();

        if (idnumber.length === 0) {
            $('#idnumber_val').text('');
            idNumberExists = false;
            return;
        }

        $.ajax({
            url: 'check_id_number.php',
            type: 'GET',
            data: { idnumber: idnumber },
            success: function (response) {
                try {
                    var data = typeof response === 'object' ? response : JSON.parse(response);

                    if (data && data.exists === true) {
                        $('#idnumber_val').text('This ID number is already taken!');
                        idNumberExists = true;
                    } else {
                        $('#idnumber_val').text('');
                        idNumberExists = false;
                    }
                } catch (e) {
                    console.error('Error parsing ID check response:', e);
                    $('#idnumber_val').text('');
                    idNumberExists = false;
                }
            },
            error: function (xhr, status, error) {
                console.error('AJAX request failed for ID check:', error);
                $('#idnumber_val').text('');
                idNumberExists = false;
            }
        });
    });
});
