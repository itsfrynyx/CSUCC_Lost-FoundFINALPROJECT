// Enhanced login form interactions
document.addEventListener('DOMContentLoaded', function() {
    const passwordToggle = document.getElementById('passwordToggle');
    const passwordInput = document.getElementById('password');
    const loginForm = document.getElementById('loginForm');
    const submitBtn = document.getElementById('submitBtn');
    
    // Password visibility toggle
    if (passwordToggle && passwordInput) {
        passwordToggle.addEventListener('click', function() {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            
            // Toggle icon
            const icon = this.querySelector('i');
            if (type === 'text') {
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });
    }
    
    // Form submission with loading state
    if (loginForm && submitBtn) {
        loginForm.addEventListener('submit', function() {
            const btnText = submitBtn.querySelector('.btn-text');
            const btnLoader = submitBtn.querySelector('.btn-loader');
            
            if (btnText && btnLoader) {
                btnText.style.display = 'none';
                btnLoader.style.display = 'inline-block';
                submitBtn.disabled = true;
            }
        });
    }
    
    // Real-time validation feedback
    const usernameInput = document.getElementById('username');
    const passwordInputForValidation = document.getElementById('password');
    
    if (usernameInput) {
        usernameInput.addEventListener('blur', function() {
            validateUsername();
            this.style.borderColor = validateUsername() ? '#28a745' : '#dc3545';
        });
        
        usernameInput.addEventListener('focus', function() {
            this.style.borderColor = '#882b2a';
        });
    }
    
    if (passwordInputForValidation) {
        passwordInputForValidation.addEventListener('blur', function() {
            validatePassword();
            this.style.borderColor = validatePassword() ? '#28a745' : '#dc3545';
        });
        
        passwordInputForValidation.addEventListener('focus', function() {
            this.style.borderColor = '#882b2a';
        });
    }
    
    // Add input animations
    const formInputs = document.querySelectorAll('.form-input');
    formInputs.forEach(input => {
        input.addEventListener('focus', function() {
            this.parentElement.classList.add('focused');
        });
        
        input.addEventListener('blur', function() {
            if (!this.value) {
                this.parentElement.classList.remove('focused');
            }
        });
    });
});

function validateUsername() {
    var username = document.getElementById("username").value;
    
    if (username.length < 4 || username.length > 30) {
        document.getElementById("username_val").innerHTML = "Enter a username between 4 and 30 characters.";
        document.getElementById("username_val").style.fontSize = "13px";
        return false; // Return false if invalid
    } else {
        document.getElementById("username_val").innerHTML = ""; // Clear error message when valid
        return true; // Return true if valid
    }
}

function validatePassword() {
    var password = document.getElementById("password").value;
    var passwordVal = document.getElementById("password_val");

    // Check length
    if (password.length < 8 || password.length > 15) {
        passwordVal.innerHTML = "You need to input a password with at least 8 and at most 15 characters!";
        passwordVal.style.fontSize = "13px";
        return false; // Check for spaces only
    }
    if (/\s/.test(password)) {
        passwordVal.innerHTML = "Password must not contain spaces!";
        passwordVal.style.fontSize = "13px";
        return false; // Return false if invalid
    }
    
    // Password is valid if it meets minimum requirements
    passwordVal.innerHTML = ""; // Clear error message when valid
    return true; // Return true if valid
}

function loginValidation() {
    var isUsernameValid = validateUsername();
    var isPasswordValid = validatePassword();

    // Prevent submission if either validation fails
    return isUsernameValid && isPasswordValid;
}

document.getElementById('username').addEventListener('input', function() {
    var usernameInput = this.value.toLowerCase();  // Convert the input to lowercase
    var regex = /[^a-z0-9._]/g; // Regular expression to match any character that is not a letter or number
    var errorMessage = document.getElementById('username_vall'); // Get the error message span

    // Check if the username starts with a dot or contains invalid characters
    if (usernameInput.startsWith('.')) {
        usernameInput = usernameInput.slice(1); // Remove the dot from the beginning
        this.value = usernameInput; // Update the input field with the sliced value
        errorMessage.textContent = 'The username should not start with a dot.'; // Display specific error message
        errorMessage.style.display = 'block'; // Display the error message
    } else if (regex.test(usernameInput)) {
        errorMessage.textContent = 'The input field should only contain letters, numbers, dots, or underscores.'; // General error message
        errorMessage.style.display = 'block'; // Display the error message
        this.value = this.value.replace(regex, ''); // Remove any non-alphanumeric characters
    } else {
        errorMessage.textContent = ''; // Clear the error message
        errorMessage.style.display = 'none'; // Hide the error message
        this.value = usernameInput; // Update the input value with the lowercase version
    }
});


// for (let i = 0; i < 100; i++) {
//     window.history.pushState(null, null, window.location.href);
// }

// function destroyHistory() {
//     // Initially push 100 states
//     for (let i = 0; i < 100; i++) {
//         window.history.pushState(null, null, window.location.href);
//     }

//     // Continuously push the current state into the history every 100 milliseconds
//     setInterval(function() {
//         window.history.pushState(null, null, window.location.href);
//     }, 100);  // Adjust the interval as needed
    
//     // Capture the popstate event and prevent back navigation
//     window.onpopstate = function() {
//         window.history.pushState(null, null, window.location.href);  // Re-push the state to block back navigation
//     };
// }
