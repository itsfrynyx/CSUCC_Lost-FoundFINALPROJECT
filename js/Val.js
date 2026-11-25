function formValidation(){
    
    var idnumber = document.getElementById("idnumber").value;
    var fname = document.getElementById("firstname").value;
    var lname = document.getElementById("lastname").value;
    var mname = document.getElementById("middlename").value;
    var street = document.getElementById("street").value;
    var barangay = document.getElementById("barangay").value;
    var municipality = document.getElementById("municipality").value;
    var province = document.getElementById("province").value;
    var country = document.getElementById("country").value;
    var postal = document.getElementById("postal").value;
    var username = document.getElementById("username").value;
    var sex = document.getElementById("sex").value;  // Assuming this is a dropdown
    var birthdate = document.getElementById("birthdate").value;  // Assuming this is a date input
    var password = document.getElementById("passw").value;
    var confirmPassword = document.getElementById("cpassword").value;
    var passwordError = document.getElementById("is-valid-password");
    var confirmPasswordError = document.getElementById("is-valid-confirmpassword");
    
    
     
    // if (idnumber == "") {
    //     document.getElementById("idnumber_val").innerHTML = "Input required!";
    //     document.getElementById("idnumber_val").style.fontSize = "13px";
    //     return false;
    // }

    if (fname == "") {
        document.getElementById("firstnamee").innerHTML = "Input required!";
        document.getElementById("firstnamee").style.fontSize = "13px";
        return false;
    }

    if (fname.length < 3) {
        document.getElementById("firstnamee").innerHTML = "You need to input at least 3 or 15 characters!";
        document.getElementById("firstnamee").style.fontSize = "13px";
        return false;
    }

    // Validating lastname
    if (lname == "") {
        document.getElementById("lastnamee").innerHTML = "Input required!";
        document.getElementById("lastnamee").style.fontSize = "13px";
        return false;
    }

    if (lname.length < 3) {
        document.getElementById("lastnamee").innerHTML = "You need to input at least 3 or 15 characters!";
        document.getElementById("lastnamee").style.fontSize = "13px";
        return false;
    }
    
    // Validating sex
    if (sex == "") {
        displayError("sex_val", "Please select your sex!");
        return false;
    } else {
        clearError("sex_val");  // Clear error if sex is selected
    }

    // Validating birthdate
    if (birthdate == "") {
        displayError("birthdate_val", "Please fill in your birthdate!");
        return false;
    } else {
        clearError("birthdate_val");  // Clear error if birthdate is provided
    }

    // Optional: You could also validate that the birthdate is reasonable (e.g., not in the future)
    var birthDateObj = new Date(birthdate);
    var today = new Date();
    if (birthDateObj > today) {
        displayError("birthdate_val", "Birthdate cannot be in the future!");
        return false;
    }
    // Validating street
    if (street == "") {
        document.getElementById("street_val").innerHTML = "Input required!";
        document.getElementById("street_val").style.fontSize = "13px";
        return false;
    }

    // Validating barangay
    if (barangay == "") {
        document.getElementById("barangay_val").innerHTML = "Input required!";
        document.getElementById("barangay_val").style.fontSize = "13px";
        return false;
    }

    if (barangay.length < 3) {
        document.getElementById("barangay_val").innerHTML = "You need to input at least 3 or 15 characters!";
        document.getElementById("barangay_val").style.fontSize = "13px";
        return false;
    }

    // Validating Municipality
    if (municipality == "") {
        document.getElementById("municipality_val").innerHTML = "Input required!";
        document.getElementById("municipality_val").style.fontSize = "13px";
        return false;
    }

    if (municipality.length < 3) {
        document.getElementById("municipality_val").innerHTML = "You need to input at least 3 or 15 characters!";
        document.getElementById("municipality_val").style.fontSize = "13px";
        return false;
    }

    // Validating Province
    if (province == "") {
        document.getElementById("province_val").innerHTML = "Input required!";
        document.getElementById("province_val").style.fontSize = "13px";
        return false;
    }

    if (province.length < 3) {
        document.getElementById("province_val").innerHTML = "You need to input at least 3 or 15 characters!";
        document.getElementById("province_val").style.fontSize = "13px";
        return false;
    }

    // Validating Country
    if (country == "") {
        document.getElementById("country_val").innerHTML = "Input required!";
        document.getElementById("country_val").style.fontSize = "13px";
        return false;
    }

    if (country.length < 3) {
        document.getElementById("country_val").innerHTML = "You need to input at least 3 or 15 characters!";
        document.getElementById("country_val").style.fontSize = "13px";
        return false;
    }

    // Validating postal code
    if (postal == "") {
        document.getElementById("postal_val").innerHTML = "Input required!";
        document.getElementById("postal_val").style.fontSize = "13px";
        return false;
    }

    if (postal.length < 4) {
        document.getElementById("postal_val").innerHTML = "You need to input at least 4 characters!";
        document.getElementById("postal_val").style.fontSize = "13px";
        return false;
    }

    // Email Validation
    var email = document.getElementById("email").value;
    if (email == "") {
        document.getElementById("email_val").innerHTML = "Input required!";
        document.getElementById("email_val").style.fontSize = "13px";
        return false;
    }

    var emailPattern = /^[a-zA-Z0-9._-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,6}$/;
    if (!email.match(emailPattern)) {
        document.getElementById("email_val").innerHTML = "Please enter a valid email!";
        document.getElementById("email_val").style.fontSize = "13px";
        return false;
    }

    // Validating username
    if (username == "") {
        document.getElementById("username_val").innerHTML = "Please fill!";
        document.getElementById("username_val").style.fontSize = "13px";
        return false;
    }

    if (username.length < 4) {
        document.getElementById("username_val").innerHTML = "You need to input 4 or 15 characters!";
        document.getElementById("username_val").style.fontSize = "13px";
        return false;
    }

    // Clear any previous error messages
    passwordError.innerHTML = '';
    confirmPasswordError.innerHTML = '';

    // Validate password field
    if (password === "") {
        passwordError.innerHTML = "Please fill out your password!";
        passwordError.style.fontSize = "13px";
        return false;
    }

    // Check if password length is valid
    if (password.length < 8) {
        passwordError.innerHTML = "Password must be at least 8 characters!";
        passwordError.style.fontSize = "13px";
        return false;
    }

    // Validate confirm password field
    if (confirmPassword === "") {
        confirmPasswordError.innerHTML = "Please confirm your password!";
        confirmPasswordError.style.fontSize = "13px";
        return false;
    }

    // Check if passwords match
    if (password !== confirmPassword) {
        confirmPasswordError.innerHTML = "Passwords do not match!";
        confirmPasswordError.style.fontSize = "13px";
        return false;
    }


    if (mname.length != 0) {
        if (mname.length < 1) {
            document.getElementById("middlenamee").innerHTML = "You need to input 1 character only!";
            return false;
        }
        if (mname.length > 20) {
            document.getElementById("middlenamee").innerHTML = "Less than 20 characters!";
            return false;
        }
    }

    return true;
}




// Helper function to display error messages
function displayError(elementId, message) {
    document.getElementById(elementId).innerHTML = message;
    document.getElementById(elementId).style.fontSize = "13px";
}

// Helper function to clear error messages
function clearError(elementId) {
    document.getElementById(elementId).innerHTML = "";
}
 // Birthdate *****

// Event listener to update age dynamically when the birthdate is inputted
document.getElementById("birthdate").addEventListener("change", function () {
    var birthdate = this.value;
    var ageField = document.getElementById("age");
    var ageValidationMessage = document.getElementById("age_val");
    var submitButton = document.querySelector(".button-reg"); // Get the submit button

    if (birthdate) {
        var birthDateObj = new Date(birthdate);
        var today = new Date();

        // Calculate age
        var age = today.getFullYear() - birthDateObj.getFullYear();
        var monthDiff = today.getMonth() - birthDateObj.getMonth();
        if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDateObj.getDate())) {
            age--;
        }

        ageField.value = age; // Update the age field

        if (age < 18) {
            ageValidationMessage.innerHTML = "Age must be 18 or above.";
            ageValidationMessage.style.fontSize = "13px";
            submitButton.disabled = true; // Disable the button if age is less than 18
        } else {
            ageValidationMessage.innerHTML = ""; // Clear error message if valid
            submitButton.disabled = false; // Enable the button if age is 18 or above
        }
    } else {
        ageField.value = ""; // Clear the age field if birthdate is invalid
        ageValidationMessage.innerHTML = "Please enter a valid birthdate.";
        ageValidationMessage.style.fontSize = "13px";
        submitButton.disabled = true; // Disable the button if no birthdate is provided
    }
});




//Auto capitalization*****

document.getElementById('firstname').addEventListener('input', function() {
    let input = this.value;
    let cleanedInput = input.replace(/\s{2,}/g, ' '); // Replace 2 or 15 spaces with one space

    let formattedInput = cleanedInput
        .split(' ')
        .map(word => word.charAt(0).toUpperCase() + word.slice(1).toLowerCase())
        .join(' ');

    this.value = formattedInput;
});

document.getElementById('lastname').addEventListener('input', function() {
    let input = this.value;
    let cleanedInput = input.replace(/\s{2,}/g, ' '); // Replace 2 or 15 spaces with one space

    let formattedInput = cleanedInput
        .split(' ')
        .map(word => word.charAt(0).toUpperCase() + word.slice(1).toLowerCase())
        .join(' ');

    this.value = formattedInput;
});


document.getElementById('middlename').addEventListener('input', function() {
    let input = this.value;
    let cleanedInput = input.replace(/\s{2,}/g, ' '); // Replace 2 or 15 spaces with one space

    let formattedInput = cleanedInput
        .split(' ')
        .map(word => word.charAt(0).toUpperCase() + word.slice(1).toLowerCase())
        .join(' ');

    this.value = formattedInput;
});


document.getElementById('street').addEventListener('input', function() {
    let input = this.value;
    let cleanedInput = input.replace(/\s{2,}/g, ' '); // Replace 2 or 15 spaces with one space

    let formattedInput = cleanedInput
        .split(' ')
        .map(word => word.charAt(0).toUpperCase() + word.slice(1).toLowerCase())
        .join(' ');

    this.value = formattedInput;
});

document.getElementById('barangay').addEventListener('input', function() {
    let input = this.value;
    let cleanedInput = input.replace(/\s{2,}/g, ' '); // Replace 2 or 15 spaces with one space

    let formattedInput = cleanedInput
        .split(' ')
        .map(word => word.charAt(0).toUpperCase() + word.slice(1).toLowerCase())
        .join(' ');

    this.value = formattedInput;
});


document.getElementById('municipality').addEventListener('input', function() {
    let input = this.value;
    let cleanedInput = input.replace(/\s{2,}/g, ' '); // Replace 2 or 15 spaces with one space

    let formattedInput = cleanedInput
        .split(' ')
        .map(word => word.charAt(0).toUpperCase() + word.slice(1).toLowerCase())
        .join(' ');

    this.value = formattedInput;
});

document.getElementById('province').addEventListener('input', function() {
    let input = this.value;
    let cleanedInput = input.replace(/\s{2,}/g, ' '); // Replace 2 or 15 spaces with one space

    let formattedInput = cleanedInput
        .split(' ')
        .map(word => word.charAt(0).toUpperCase() + word.slice(1).toLowerCase())
        .join(' ');

    this.value = formattedInput;
});

document.getElementById('country').addEventListener('input', function() {
    let input = this.value;
    let cleanedInput = input.replace(/\s{2,}/g, ' '); // Replace 2 or 15 spaces with one space

    let formattedInput = cleanedInput
        .split(' ')
        .map(word => word.charAt(0).toUpperCase() + word.slice(1).toLowerCase())
        .join(' ');

    this.value = formattedInput;
});




//no spaces

document.getElementById('firstname').oninput = function() {
    // Remove leading spaces
    this.value = this.value.replace(/^\s+/g, '');

    this.value = this.value.replace(/^[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]+/, '');
}
    

document.getElementById('lastname').oninput = function() {
    // Remove leading spaces
    this.value = this.value.replace(/^\s+/g, '');

    this.value = this.value.replace(/^[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]+/, '');
}

document.getElementById('middlename').oninput = function() {
    // Remove leading spaces
    this.value = this.value.replace(/^\s+/g, '');

    this.value = this.value.replace(/^[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]+/, '');
}

document.getElementById('suffix').oninput = function() {
    // Remove leading spaces
    this.value = this.value.replace(/^\s+/g, '');

    this.value = this.value.replace(/^[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]+/, '');
}

// document.getElementById('email').oninput = function() {
//     // Remove leading spaces
//     this.value = this.value.replace(/^\s+/g, '');

//     this.value = this.value.replace(/^[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]+/, '');
// }


document.getElementById('street').oninput = function() {
    // Remove leading spaces
    this.value = this.value.replace(/^\s+/g, '');

    this.value = this.value.replace(/^[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]+/, '');
}


document.getElementById('barangay').oninput = function() {
    // Remove leading spaces
    this.value = this.value.replace(/^\s+/g, '');

    this.value = this.value.replace(/^[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]+/, '');
}

document.getElementById('municipality').oninput = function() {
    // Remove leading spaces
    this.value = this.value.replace(/^\s+/g, '');

    this.value = this.value.replace(/^[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]+/, '');
}

document.getElementById('province').oninput = function() {
    // Remove leading spaces
    this.value = this.value.replace(/^\s+/g, '');

    this.value = this.value.replace(/^[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]+/, '');
}
document.getElementById('postal').oninput = function() {
    // Remove leading spaces
    this.value = this.value.replace(/^\s+/g, '');

    this.value = this.value.replace(/^[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]+/, '');
}

document.getElementById('country').oninput = function() {
    // Remove leading spaces
    this.value = this.value.replace(/^\s+/g, '');

    this.value = this.value.replace(/^[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]+/, '');
}


document.getElementById('passw').oninput = function() {
    var inputElement = document.getElementById('passw');

    // Check if the element exists
    if (inputElement) {
        // Remove leading spaces
        inputElement.value = inputElement.value.replace(/^\s+/g, '');

       
    }
}

document.getElementById('cpassword').oninput = function() {
    var inputElement = document.getElementById('cpassword');

    // Check if the element exists
    if (inputElement) {
        // Remove leading spaces
        inputElement.value = inputElement.value.replace(/^\s+/g, '');

        
    }
}


// Alert if its number and symbols

document.getElementById('username').addEventListener('input', function() {
    var usernameInput = this.value.toLowerCase();  // Convert the input to lowercase
    var regex = /[^a-z0-9._]/g; // Regular expression to match any character that is not a letter, number, dot, or underscore
    var errorMessage = document.getElementById('username_vall'); // Get the error message span

    // Check if the username starts with a digit
    if (/^\d/.test(usernameInput)) {
        errorMessage.textContent = 'The username should start with a letter, not a number.'; // Specific error message
        errorMessage.style.display = 'block'; // Display the error message
        this.value = usernameInput.slice(1); // Remove the digit from the beginning
    }
    // Check if the username starts with a special character (dot or underscore)
    else if (/^[._]/.test(usernameInput)) {
        errorMessage.textContent = 'The username should not start with a special character (dot or underscore).'; // Error message for special character at the start
        errorMessage.style.display = 'block'; // Display the error message
        this.value = usernameInput.slice(1); // Remove the special character from the beginning
    }
    // Check if the username contains invalid characters
    else if (regex.test(usernameInput)) {
        errorMessage.textContent = 'The input field should only contain letters, numbers, dots, or underscores.'; // General error message
        errorMessage.style.display = 'block'; // Display the error message
        this.value = this.value.replace(regex, ''); // Remove any non-alphanumeric characters
    } 
    // If the input is valid, hide the error message and update the value
    else {
        errorMessage.textContent = ''; // Clear the error message
        errorMessage.style.display = 'none'; // Hide the error message
        this.value = usernameInput; // Update the input value with the lowercase version
    }
});


document.getElementById('email').addEventListener('input', function () {
    // Convert the input to lowercase and update the field value
    this.value = this.value.toLowerCase();

    var emailInput = this.value; // Retrieve the updated input value
    var emailRegex = /^[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}$/; // Regular expression for email validation
    var errorMessage = document.getElementById('email_vall'); // Get the error message span

    // Check if the input matches the email format
    if (!emailRegex.test(emailInput)) {
        errorMessage.textContent = 'Please enter a valid email address.'; // Display specific error message
        errorMessage.style.display = 'block'; // Display the error message

        // Replace all invalid characters except @, _, -, and .
        var regex = /[^a-z0-9@_.-]/g; // Define regex for invalid characters
        this.value = this.value.replace(regex, ''); // Remove invalid characters
    } else {
        errorMessage.textContent = ''; // Clear the error message
        errorMessage.style.display = 'none'; // Hide the error message
    }
});








document.getElementById('firstname').addEventListener('input', function() {
    var fnameInput = this.value;
    var regex = /[^a-zA-Z\s]/g; // Regular expression to match any character that is not a letter or space
    var errorSpan = document.getElementById('firstnamee'); // Get the error message span

    if (regex.test(fnameInput)) {
        errorSpan.textContent = 'The input field should only contain letters and spaces.'; // Display error message
        this.value = this.value.replace(regex, ''); // Remove any non-letter and non-space characters
    } else {
        errorSpan.textContent = ''; // Clear the error message if the input is valid
    }
});

document.getElementById('lastname').addEventListener('input', function() {
    var fnameInput = this.value;
    var regex = /[^a-zA-Z\s]/g; // Regular expression to match any character that is not a letter or space
    var errorSpan = document.getElementById('lastnamee'); // Get the error message span

    if (regex.test(fnameInput)) {
        errorSpan.textContent = 'The input field should only contain letters and spaces.'; // Display error message
        this.value = this.value.replace(regex, ''); // Remove any non-letter and non-space characters
    } else {
        errorSpan.textContent = ''; // Clear the error message if the input is valid
    }
});

document.getElementById('middlename').addEventListener('input', function() {
    var fnameInput = this.value;
    var regex = /[^a-zA-Z\s]/g; // Regular expression to match any character that is not a letter or space
    var errorSpan = document.getElementById('middlenamee'); // Get the error message span

    if (regex.test(fnameInput)) {
        errorSpan.textContent = 'The input field should only contain letters and spaces.'; // Display error message
        this.value = this.value.replace(regex, ''); // Remove any non-letter and non-space characters
    } else {
        errorSpan.textContent = ''; // Clear the error message if the input is valid
    }
});


// Function to validate the Purok (street) input field
document.getElementById('street').addEventListener('input', function() {
    var fnameInput = this.value;
    var regex = /[^a-zA-Z0-9\s-]/g; // Regular expression to match any character that is not a letter, number, space, or hyphen
    var errorMessage = document.getElementById('street_val'); // Get the span element for error message

    // Prevent values that start with a number followed by letters, spaces, or hyphens
    if (/^\d/.test(fnameInput)) {
        errorMessage.textContent = 'Input cannot start with a number. It must start with letters.';
        // Remove the invalid number at the start
        this.value = this.value.replace(/^\d/, '');
    }   
    // Ensure that letters must appear before numbers (i.e., no numbers before letters)
    else if (/\d[a-zA-Z]/.test(fnameInput)) {
        errorMessage.textContent = 'Numbers should not appear before letters.';
        // Remove the invalid number before letters pattern
        this.value = this.value.replace(/\d[a-zA-Z]/, '');
    } 
    // If any disallowed characters are present
    else if (regex.test(fnameInput)) {
        errorMessage.textContent = 'The input should only contain letters, numbers, spaces, and hyphens.';
        this.value = this.value.replace(regex, ''); // Remove disallowed characters
    } else {
        errorMessage.textContent = ''; // Clear the error message when the input is valid
    }
});

document.getElementById('barangay').addEventListener('input', function() {
    var fnameInput = this.value;
    var regex = /[^a-zA-Z0-9\s-]/g; // Regular expression to match any character that is not a letter, number, space, or hyphen
    var errorMessage = document.getElementById('barangay_val'); // Get the span element for error message

    // Prevent values that start with a number followed by letters
    if (/^\d/.test(fnameInput)) {
        errorMessage.textContent = 'Input cannot start with a number. It must start with letters.';
        // Remove the invalid number at the start
        this.value = this.value.replace(/^\d/, '');
    } 
    // Ensure that numbers appear only after letters, not before
    else if (/[a-zA-Z]\d/.test(fnameInput)) {
        errorMessage.textContent = 'Input must start with letters followed by numbers.';
        // Optional: you can clean up the value or allow further validation
        // Removing the first instance of a number after letters
        this.value = this.value.replace(/[a-zA-Z]\d/, (match) => match[0] + match[1]);
    }
    // If any disallowed characters are present
    else if (regex.test(fnameInput)) {
        errorMessage.textContent = 'The input should only contain letters, numbers, spaces, and hyphens.';
        this.value = this.value.replace(regex, ''); // Remove disallowed characters
    } else {
        errorMessage.textContent = ''; // Clear the error message when the input is valid
    }
});



document.getElementById('municipality').addEventListener('input', function() {
    var fnameInput = this.value;
    var regex = /[^a-zA-Z\s]/g; // Regular expression to match any character that is not a letter or space
    var errorSpan = document.getElementById('municipality_val'); // Get the error message span

    if (regex.test(fnameInput)) {
        errorSpan.textContent = 'The input field should only contain letters and spaces.'; // Display error message
        this.value = this.value.replace(regex, ''); // Remove any non-letter and non-space characters
    } else {
        errorSpan.textContent = ''; // Clear the error message if the input is valid
    }
});
document.getElementById('province').addEventListener('input', function() {
    var fnameInput = this.value;
    var regex = /[^a-zA-Z\s]/g; // Regular expression to match any character that is not a letter or space
    var errorSpan = document.getElementById('province_val'); // Get the error message span

    if (regex.test(fnameInput)) {
        errorSpan.textContent = 'The input field should only contain letters and spaces.'; // Display error message
        this.value = this.value.replace(regex, ''); // Remove any non-letter and non-space characters
    } else {
        errorSpan.textContent = ''; // Clear the error message if the input is valid
    }
});
document.getElementById('country').addEventListener('input', function() {
    var fnameInput = this.value;
    var regex = /[^a-zA-Z\s]/g; // Regular expression to match any character that is not a letter or space
    var errorSpan = document.getElementById('country_val'); // Get the error message span

    if (regex.test(fnameInput)) {
        errorSpan.textContent = 'The input field should only contain letters and spaces.'; // Display error message
        this.value = this.value.replace(regex, ''); // Remove any non-letter and non-space characters
    } else {
        errorSpan.textContent = ''; // Clear the error message if the input is valid
    }
});
//postal validation
document.getElementById('postal').addEventListener('input', function() {
    var postalInput = this.value;
    var regex = /\D/; // Regular expression to match any non-digit character
    var errorSpan = document.getElementById('postal_val'); // Get the error message span

    if (regex.test(postalInput)) {
        errorSpan.textContent = 'Enter numbers only in the zipcode field.'; // Display error message
        this.value = this.value.replace(regex, ''); // Remove any non-numeric characters
    } else {
        errorSpan.textContent = ''; // Clear the error message if the input is valid
    }
});



// //id validation
// document.getElementById('idnumber').addEventListener('input', function () {
//     var input = this.value;

//     // Only proceed with validation if there is input in the field
//     if (input.length > 0) {
//         // Regular expressions for the two valid formats
//         var regex1 = /^\d{4}-\d{4}$/; // Format: XXXX-XXXX

//         // Remove invalid characters (only allow digits and a single hyphen)
//         this.value = input.replace(/[^0-9-]/g, '');

//         // Automatically format the input: insert a hyphen after the first 4 digits
//         if (this.value.length === 5 && this.value[4] !== '-') {
//             this.value = this.value.slice(0, 4) + '-' + this.value.slice(4);
//         }

//         // Limit the length to 10 characters (for format XXXX-XXXX) or 11 characters (for format XXXX-XXXXX)
//         if (this.value.length > 10) {
//             this.value = this.value.slice(0, 10);
//         }
        
//     }
// });
document.getElementById('formSubmitter').addEventListener('submit', function (e) {
    var hasError = false;

    var idnumber = document.getElementById('idnumber').value;
    var idError = document.getElementById('idnumber_val');
    var regex1 = /^\d{4}-\d{4}$/;

    // Validate ID number locally
    if (idnumber === "") {
        idError.textContent = "Input required!";
        idError.style.color = 'red';
        idError.style.fontSize = '13px';
        hasError = true;
    } else if (!regex1.test(idnumber)) {
        idError.textContent = "Invalid ID number! Must be in the format XXXX-XXXX (e.g., 2022-1439).";
        idError.style.color = 'red';
        idError.style.fontSize = '13px';
        hasError = true;
    } else if (idNumberExists) {
        // Check if ID number already exists (from AJAX validation)
        idError.textContent = "This ID number is already taken!";
        idError.style.color = 'red';
        idError.style.fontSize = '13px';
        hasError = true;
    } else {
        idError.textContent = "";
    }

    if (!hasError) {
        if (!formValidation()) {
            hasError = true;
        }
    }

    if (hasError) {
        e.preventDefault();
    }
});






// document.getElementById('idnumber').addEventListener('blur', function () {
//     var input = this.value.trim(); // Trim whitespace just in case

//     // Regular expression for the valid format
//     var regex1 = /^\d{4}-\d{4}$/; // Format: XXXX-XXXX

//     // Get the span element to display the error message
//     var errorMessage = document.getElementById('idnumber_val');

//     // Check if the input is not empty and if it matches the regex
//     if (input !== '' && !regex1.test(input)) {
//         errorMessage.textContent = "Invalid ID number! Must be in the format XXXX-XXXX (e.g., 2022-0519)."; // Display the error
//         errorMessage.style.color = 'red'; // Ensure error text is red
//     } else {
//         errorMessage.textContent = ''; // Clear the error message if the input is valid
//     }
// });

// document.getElementById('idnumber').addEventListener('input', function () {
//     var input = this.value;
//     var errorMessage = document.getElementById('idnumber_val'); // Span element for error message

//     // Regular expression for the valid format (XXXX-XXXX)
//     var regex1 = /^\d{4}-\d{4}$/;

//     // Remove invalid characters (only allow digits and a single hyphen)
//     this.value = input.replace(/[^0-9-]/g, '');

//     // Automatically format the input: insert a hyphen after the first 4 digits if necessary
//     if (this.value.length === 5 && this.value[4] !== '-') {
//         this.value = this.value.slice(0, 4) + '-' + this.value.slice(4);
//     }

//     // Limit the length to 10 characters (for format XXXX-XXXX)
//     if (this.value.length > 10) {
//         this.value = this.value.slice(0, 10);
//     }

//     // Check if the input matches the valid format
//     if (this.value !== '' && !regex1.test(this.value)) {
//         // Display the error message if the input does not match the valid format
//         errorMessage.textContent = "Invalid ID number! Must be in the format XXXX-XXXX (e.g., 2022-0519).";
//         errorMessage.style.color = 'red';
//     } else if (this.value === '') {
//         // If the input is empty, show the "Please fill!" message
//         errorMessage.textContent = "Please fill!";
//         errorMessage.style.color = 'red';
//         errorMessage.style.fontSize = '13px';
//     } else {
//         // Clear the error message if the input is valid
//         errorMessage.textContent = '';
//     }
// });







// Validation of redundant letters

    document.getElementById('firstname').addEventListener('input', function (event) {
        const inputElement = event.target;
        let inputValue = inputElement.value;
        const errorMessageElement = document.getElementById('firstnamee');

        // Clear any previous error messages
        errorMessageElement.textContent = '';

        // Initialize variables to track the last character and its count
        let lastChar = '';
        let count = 0;

        // Iterate through the input value to check for consecutive letters
        for (let i = 0; i < inputValue.length; i++) {
            const currentChar = inputValue[i].toLowerCase();

            // If the current character is the same as the last, increment the count
            if (currentChar === lastChar) {
                count++;
                // If count reaches 3, show error message and remove the third consecutive letter
                if (count === 3) {
                   
                    inputValue = inputValue.slice(0, i) + inputValue.slice(i + 1); // Remove the third character
                    inputElement.value = inputValue; // Update the input value
                    return; // Exit the function to avoid further processing
                }
            } else {
                // Reset count if a different character is encountered
                lastChar = currentChar;
                count = 1; // Start counting this new character
            }
        }
    });

document.getElementById('lastname').addEventListener('input', function (event) {
    const inputElement = event.target;
    let inputValue = inputElement.value;
    const errorMessageElement = document.getElementById('lastname_val');

    // Clear any previous error messages
    errorMessageElement.textContent = '';

    // Initialize variables to track the last character and its count
    let lastChar = '';
    let count = 0;

    // Iterate through the input value to check for consecutive letters
    for (let i = 0; i < inputValue.length; i++) {
        const currentChar = inputValue[i];

        // If the current character is the same as the last, increment the count
        if (currentChar === lastChar) {
            count++;
            // If count reaches 3, show error message and remove the third consecutive letter
            if (count === 3) {
                
                inputValue = inputValue.slice(0, i) + inputValue.slice(i + 1); // Remove the third character
                inputElement.value = inputValue; // Update the input value
                return; // Exit the function to avoid further processing
            }
        } else {
            // Reset count if a different character is encountered
            lastChar = currentChar;
            count = 1; // Start counting this new character
        }
    }
});

document.getElementById('middlename').addEventListener('input', function (event) {
    const inputElement = event.target;
    let inputValue = inputElement.value;
    const errorMessageElement = document.getElementById('middlenamee');

    // Clear any previous error messages
    errorMessageElement.textContent = '';

    // Initialize variables to track the last character and its count
    let lastChar = '';
    let count = 0;

    // Iterate through the input value to check for consecutive letters
    for (let i = 0; i < inputValue.length; i++) {
        const currentChar = inputValue[i];

        // If the current character is the same as the last, increment the count
        if (currentChar === lastChar) {
            count++;
            // If count reaches 3, show error message and remove the third consecutive letter
            if (count === 3) {
            
                inputValue = inputValue.slice(0, i) + inputValue.slice(i + 1); // Remove the third character
                inputElement.value = inputValue; // Update the input value
                return; // Exit the function to avoid further processing
            }
        } else {
            // Reset count if a different character is encountered
            lastChar = currentChar;
            count = 1; // Start counting this new character
        }
    }
});


document.getElementById('street').addEventListener('input', function (event) {
    const inputElement = event.target;
    let inputValue = inputElement.value;
    const errorMessageElement = document.getElementById('street_val');

    // Clear any previous error messages
    errorMessageElement.textContent = '';

    // Initialize variables to track the last character and its count
    let lastChar = '';
    let count = 0;

    // Iterate through the input value to check for consecutive letters
    for (let i = 0; i < inputValue.length; i++) {
        const currentChar = inputValue[i];

        // If the current character is the same as the last, increment the count
        if (currentChar === lastChar) {
            count++;
            // If count reaches 3, show error message and remove the third consecutive letter
            if (count === 3) {
                
                inputValue = inputValue.slice(0, i) + inputValue.slice(i + 1); // Remove the third character
                inputElement.value = inputValue; // Update the input value
                return; // Exit the function to avoid further processing
            }
        } else {
            // Reset count if a different character is encountered
            lastChar = currentChar;
            count = 1; // Start counting this new character
        }
    }
});

document.getElementById('email').addEventListener('input', function (event) {
    const inputElement = event.target;
    let inputValue = inputElement.value;
    const errorMessageElement = document.getElementById('email_valll');

    // Clear any previous error messages
    errorMessageElement.textContent = '';

    // Initialize variables to track the last character and its count
    let lastChar = '';
    let count = 0;

    // Iterate through the input value to check for consecutive letters
    for (let i = 0; i < inputValue.length; i++) {
        const currentChar = inputValue[i];

        // If the current character is the same as the last, increment the count
        if (currentChar === lastChar) {
            count++;
            // If count reaches 3, show error message and remove the third consecutive letter
            if (count === 3) {
                
                inputValue = inputValue.slice(0, i) + inputValue.slice(i + 1); // Remove the third character
                inputElement.value = inputValue; // Update the input value
                return; // Exit the function to avoid further processing
            }
        } else {
            // Reset count if a different character is encountered
            lastChar = currentChar;
            count = 1; // Start counting this new character
        }
    }
});

document.getElementById('username').addEventListener('input', function (event) {
    // const inputElement = event.target;
    // let inputValue = inputElement.value;
    // const errorMessageElement = document.getElementById('username_valll');

    // // Clear any previous error messages
    // errorMessageElement.textContent = '';

    // // Initialize variables to track the last character and its count
    // let lastChar = '';
    // let count = 0;

    // // Iterate through the input value to check for consecutive letters
    // for (let i = 0; i < inputValue.length; i++) {
    //     const currentChar = inputValue[i];

    //     // If the current character is the same as the last, increment the count
    //     if (currentChar === lastChar) {
    //         count++;
    //         // If count reaches 3, show error message and remove the third consecutive letter
    //         if (count === 3) {
    //             
    //             inputValue = inputValue.slice(0, i) + inputValue.slice(i + 1); // Remove the third character
    //             inputElement.value = inputValue; // Update the input value
    //             return; // Exit the function to avoid further processing
    //         }
    //     } else {
    //         // Reset count if a different character is encountered
    //         lastChar = currentChar;
    //         count = 1; // Start counting this new character
    //     }
    // }
});

// document.getElementById('email').addEventListener('input', function(event) {
//     const inputElement = event.target;
//     let inputValue = inputElement.value;

//     // Initialize variables to track the last character and its count
//     let lastChar = '';
//     let count = 0;

//     // Iterate through the input value to check for consecutive letters
//     for (let i = 0; i < inputValue.length; i++) {
//         const currentChar = inputValue[i];

//         // If the current character is the same as the last, increment the count
//         if (currentChar === lastChar) {
//             count++;
//             // If count reaches 3, alert the user and trim the input
//             if (count >= 3) {
//                 
//                 inputValue = inputValue.slice(0, i - 1); // Keep only up to the last valid input
//                 inputElement.value = inputValue; // Update the input value
//                 return; // Exit the function
//             }
//         } else {
//             // Reset count if a different character is encountered
//             lastChar = currentChar;
//             count = 1; // Start counting this new character
//         }
//     }
// });

document.getElementById('barangay').addEventListener('input', function (event) {
    const inputElement = event.target;
    let inputValue = inputElement.value;
    const errorMessageElement = document.getElementById('barangay_val');

    // Clear any previous error messages
    errorMessageElement.textContent = '';

    // Initialize variables to track the last character and its count
    let lastChar = '';
    let count = 0;

    // Iterate through the input value to check for consecutive letters
    for (let i = 0; i < inputValue.length; i++) {
        const currentChar = inputValue[i];

        // If the current character is the same as the last, increment the count
        if (currentChar === lastChar) {
            count++;
            // If count reaches 3, show error message and remove the third consecutive letter
            if (count === 3) {
                
                inputValue = inputValue.slice(0, i) + inputValue.slice(i + 1); // Remove the third character
                inputElement.value = inputValue; // Update the input value
                return; // Exit the function to avoid further processing
            }
        } else {
            // Reset count if a different character is encountered
            lastChar = currentChar;
            count = 1; // Start counting this new character
        }
    }
});


document.getElementById('municipality').addEventListener('input', function (event) {
    const inputElement = event.target;
    let inputValue = inputElement.value;
    const errorMessageElement = document.getElementById('municipality_val');

    // Clear any previous error messages
    errorMessageElement.textContent = '';

    // Initialize variables to track the last character and its count
    let lastChar = '';
    let count = 0;

    // Iterate through the input value to check for consecutive letters
    for (let i = 0; i < inputValue.length; i++) {
        const currentChar = inputValue[i];

        // If the current character is the same as the last, increment the count
        if (currentChar === lastChar) {
            count++;
            // If count reaches 3, show error message and remove the third consecutive letter
            if (count === 3) {
                
                inputValue = inputValue.slice(0, i) + inputValue.slice(i + 1); // Remove the third character
                inputElement.value = inputValue; // Update the input value
                return; // Exit the function to avoid further processing
            }
        } else {
            // Reset count if a different character is encountered
            lastChar = currentChar;
            count = 1; // Start counting this new character
        }
    }
});

document.getElementById('province').addEventListener('input', function (event) {
    const inputElement = event.target;
    let inputValue = inputElement.value;
    const errorMessageElement = document.getElementById('province_val');

    // Clear any previous error messages
    errorMessageElement.textContent = '';

    // Initialize variables to track the last character and its count
    let lastChar = '';
    let count = 0;

    // Iterate through the input value to check for consecutive letters
    for (let i = 0; i < inputValue.length; i++) {
        const currentChar = inputValue[i];

        // If the current character is the same as the last, increment the count
        if (currentChar === lastChar) {
            count++;
            // If count reaches 3, show error message and remove the third consecutive letter
            if (count === 3) {
               
                inputValue = inputValue.slice(0, i) + inputValue.slice(i + 1); // Remove the third character
                inputElement.value = inputValue; // Update the input value
                return; // Exit the function to avoid further processing
            }
        } else {
            // Reset count if a different character is encountered
            lastChar = currentChar;
            count = 1; // Start counting this new character
        }
    }
});

document.getElementById('country').addEventListener('input', function (event) {
    const inputElement = event.target;
    let inputValue = inputElement.value;
    const errorMessageElement = document.getElementById('country_val');

    // Clear any previous error messages
    errorMessageElement.textContent = '';

    // Initialize variables to track the last character and its count
    let lastChar = '';
    let count = 0;

    // Iterate through the input value to check for consecutive letters
    for (let i = 0; i < inputValue.length; i++) {
        const currentChar = inputValue[i];

        // If the current character is the same as the last, increment the count
        if (currentChar === lastChar) {
            count++;
            // If count reaches 3, show error message and remove the third consecutive letter
            if (count === 3) {
               
                inputValue = inputValue.slice(0, i) + inputValue.slice(i + 1); // Remove the third character
                inputElement.value = inputValue; // Update the input value
                return; // Exit the function to avoid further processing
            }
        } else {
            // Reset count if a different character is encountered
            lastChar = currentChar;
            count = 1; // Start counting this new character
        }
    }
});


// Checking password strength and requirements in real-time
document.getElementById('passw').addEventListener('input', (e) => {
    document.getElementById('is-valid-password').innerHTML = "";
    var password = e.target.value;
    var strengthIndicator = document.getElementById('password-strength');
    var ReqIndicator = document.getElementById('password-req');
    var submitButton = document.querySelector('button[type="submit"]'); // Get the submit button

    // Password criteria
    var hasUppercase = /[A-Z]/.test(password);
    var hasNumber = /\d/.test(password);
    var hasSpecialChar = /\W/.test(password);
    var isLengthValid = password.length >= 8;

    // Real-time feedback for unmet criteria
    var unmetCriteria = [];
    if (!isLengthValid) unmetCriteria.push("at least 8 characters long");
    if (!hasUppercase) unmetCriteria.push("at least one uppercase letter");
    if (!hasNumber) unmetCriteria.push("at least one number");
    if (!hasSpecialChar) unmetCriteria.push("at least one special character");

    if (unmetCriteria.length > 0) {
        ReqIndicator.innerHTML = "Password must have: " + unmetCriteria.join(", ");
        ReqIndicator.style.color = 'red';
    } else {
        ReqIndicator.innerHTML = ""; // Clear if no unmet criteria
    }

    // Strength indication
    if (!password) {
        strengthIndicator.innerText = '';
        submitButton.disabled = false; // Enable the submit button if password is empty
        return;
    }

    // Check strength level
    if (hasUppercase && hasNumber && hasSpecialChar && isLengthValid) {
        strengthIndicator.innerText = 'Password is Strong';
        strengthIndicator.style.color = 'green';
        submitButton.disabled = false; // Enable the submit button for strong passwords
    } else if (isLengthValid && ((hasUppercase && hasNumber) || (hasUppercase && hasSpecialChar) || (hasNumber && hasSpecialChar))) {
        strengthIndicator.innerText = 'Password is Medium';
        strengthIndicator.style.color = '#eeb600';
        submitButton.disabled = true; // Disable the submit button for medium passwords
    } else {
        strengthIndicator.innerText = 'Password is Weak';
        strengthIndicator.style.color = 'red';
        submitButton.disabled = true; // Disable the submit button for weak passwords
    }
});





//validation for suffix 
document.getElementById("suffix").addEventListener("blur", function() {
    var suffixInput = document.getElementById("suffix");
    var suffixValue = suffixInput.value.trim().toUpperCase(); // Convert all input to uppercase
    var suffixValError = document.getElementById("suffix_val"); // Get the error span

    // Automatically correct "SR" to "Sr." and "JR" to "Jr."
    if (suffixValue === "SR") {
        suffixValue = "Sr.";
    } else if (suffixValue === "JR") {
        suffixValue = "Jr.";
    }

    // Allow empty suffix or valid Roman numerals, "Jr.", or "Sr."
    var validSuffixRegex = /^(M{0,3})(CM|CD|D?C{0,3})(XC|XL|L?X{0,3})(IX|IV|V?I{0,3})$|^Jr\.$|^Sr\.$/;

    // Clear previous error message
    suffixValError.textContent = "";

    if (!validSuffixRegex.test(suffixValue)) {
        // Display the error message in the span
        suffixValError.textContent = "Invalid Suffix (example format: Jr., Sr., I, II, III, IV, V, ...)";
        
        // Clear the value of the suffix input
        suffixInput.value = "";
    } else {
        // Update the input value with the corrected suffix
        suffixInput.value = suffixValue;
    }
});







//verify if the password is a match
// Get elements

var submitButton = document.querySelector('button[type="submit"]');  // Assuming the submit button is a <button>

// Function to handle enabling/disabling the submit button
// function toggleSubmitButton() {
//     var password = passwordInput.value;
//     var confirmPassword = confirmPasswordInput.value;

//     if (password !== confirmPassword) {
//         submitButton.disabled = true; // Disable button if passwords don't match
//     } else {
//         submitButton.disabled = false; // Enable button if passwords match
//     }
// }

// Verify if the password is a match
// passwordInput.addEventListener('input', (e) => {
//     var password = passwordInput.value;
//     var confirmPassword = confirmPasswordInput.value;

//     if (password === "") {
//         document.getElementById("is-valid-confirmpassword").innerHTML = "";
//         document.getElementById("confirmpassword-label").style.color = "black";
//         document.getElementById("cpassword").style.border = "";
//         document.getElementById("cpassword").style.borderStyle = "";
//         document.getElementById("password-label").style.color = "black";
//         document.getElementById("passw").style.border = "";
//         document.getElementById("passw").style.borderStyle = "";
//     }

//     if (password !== confirmPassword && confirmPassword !== "") {
//         document.getElementById("is-valid-confirmpassword").innerHTML = "Please make sure your passwords match.";
//         document.getElementById("confirmpassword-label").style.color = "red";
//         document.getElementById("cpassword").style.border = "1px solid red";
//         document.getElementById("cpassword").style.borderStyle = "none none solid none";
//     } else {
//         document.getElementById("is-valid-confirmpassword").innerHTML = "";
//         document.getElementById("confirmpassword-label").style.color = "black";
//         document.getElementById("cpassword").style.border = "";
//         document.getElementById("cpassword").style.borderStyle = "";
//     }

//     toggleSubmitButton(); // Call the function to check the password match and toggle submit button
// });

// confirmPasswordInput.addEventListener('input', (e) => {
//     var password = passwordInput.value;
//     var confirmPassword = confirmPasswordInput.value;

//     if (confirmPassword === "") {
//         document.getElementById("is-valid-confirmpassword").innerHTML = "";
//         document.getElementById("confirmpassword-label").style.color = "black";
//         document.getElementById("cpassword").style.border = "";
//         document.getElementById("password-label").style.color = "black";
//         document.getElementById("passw").style.border = "";
//         document.getElementById("passw").style.borderStyle = "";
//     }

//     if (password !== confirmPassword) {
//         document.getElementById("is-valid-confirmpassword").innerHTML = "Please make sure your passwords match.";
//         document.getElementById("confirmpassword-label").style.color = "red";
//         document.getElementById("cpassword").style.border = "1px solid red";
//         document.getElementById("cpassword").style.borderStyle = "none none solid none";
//         document.getElementById("password-label").style.color = "red";
//         document.getElementById("passw").style.border = "1px solid red";
//         document.getElementById("passw").style.borderStyle = "none none solid none";
//     } else {
//         document.getElementById("is-valid-confirmpassword").innerHTML = "";
//         document.getElementById("confirmpassword-label").style.color = "black";
//         document.getElementById("cpassword").style.border = "";
//         document.getElementById("password-label").style.color = "black";
//         document.getElementById("passw").style.border = "";
//         document.getElementById("passw").style.borderStyle = "";
//     }

//     toggleSubmitButton(); // Call the function to check the password match and toggle submit button
// });


//remove space in password

document.getElementById('passw').addEventListener('input', function () {
    var passwordInput = this.value;
    var hasSpace = /\s/.test(passwordInput);

    if (hasSpace) {
        alert('Password cannot contain spaces.');
        this.value = this.value.replace(/\s/g, ''); // Remove spaces
    }

    var isPasswordValidMessage = document.getElementById('is-valid-password');
    isPasswordValidMessage.textContent = ''; // Clear the error message
});
document.getElementById('cpassword').addEventListener('input', function () {
    var passwordInput = this.value;
    var hasSpace = /\s/.test(passwordInput);

    if (hasSpace) {
        alert('Password cannot contain spaces.');
        this.value = this.value.replace(/\s/g, ''); // Remove spaces
    }

    var isPasswordValidMessage = document.getElementById('is-valid-password');
    isPasswordValidMessage.textContent = ''; // Clear the error message
});

