// Admin Add Form Validation - Adapted from Register.php validation
// This script provides comprehensive validation for the Add Admin Modal

// Global flag for ID number existence check
var adminIdNumberExists = false;
var adminEmailExists = false;
var adminUsernameExists = false;

// AJAX Check for ID Number availability
function checkIdNumberAvailability() {
    var idnumberField = document.getElementById("add_idnumber");
    if (!idnumberField) return;
    
    idnumberField.addEventListener('keyup', function() {
        var idnumber = this.value.trim();
        
        if (idnumber.length > 0) {
            // Use jQuery AJAX if available, otherwise use fetch
            if (typeof $ !== 'undefined') {
                $.ajax({
                    url: './check_id_number.php',
                    type: 'GET',
                    data: { idnumber: idnumber },
                    success: function(response) {
                        try {
                            var data = typeof response === 'string' ? JSON.parse(response) : response;
                            if (data.exists) {
                                showError('add_idnumber_error', 'This ID number is already taken!');
                                adminIdNumberExists = true;
                                idnumberField.classList.add('error');
                                idnumberField.classList.remove('valid');
                            } else {
                                clearError('add_idnumber_error');
                                adminIdNumberExists = false;
                                idnumberField.classList.remove('error');
                                idnumberField.classList.add('valid');
                            }
                        } catch (e) {
                            console.error("Error parsing JSON response:", e);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error("AJAX request failed:", error);
                    }
                });
            } else {
                // Fallback to fetch API
                fetch('./check_id_number.php?idnumber=' + encodeURIComponent(idnumber))
                    .then(response => response.json())
                    .then(data => {
                        if (data.exists) {
                            showError('add_idnumber_error', 'This ID number is already taken!');
                            adminIdNumberExists = true;
                            idnumberField.classList.add('error');
                            idnumberField.classList.remove('valid');
                        } else {
                            clearError('add_idnumber_error');
                            adminIdNumberExists = false;
                            idnumberField.classList.remove('error');
                            idnumberField.classList.add('valid');
                        }
                    })
                    .catch(error => {
                        console.error("Fetch request failed:", error);
                    });
            }
        } else {
            clearError('add_idnumber_error');
            adminIdNumberExists = false;
            idnumberField.classList.remove('error', 'valid');
        }
    });
}

// AJAX Check for Email availability
function checkEmailAvailability() {
    var emailField = document.getElementById("add_email");
    if (!emailField) return;
    
    emailField.addEventListener('keyup', function() {
        var email = this.value.trim();
        
        if (email.length > 0) {
            if (typeof $ !== 'undefined') {
                $.ajax({
                    url: './check_email.php',
                    type: 'GET',
                    data: { email: email },
                    success: function(response) {
                        try {
                            var data = typeof response === 'string' ? JSON.parse(response) : response;
                            if (data.exists) {
                                showError('add_email_error', 'This email is already taken!');
                                adminEmailExists = true;
                                emailField.classList.add('error');
                                emailField.classList.remove('valid');
                            } else {
                                clearError('add_email_error');
                                adminEmailExists = false;
                                emailField.classList.remove('error');
                                if (email.match(/^[a-zA-Z0-9._-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,6}$/)) {
                                    emailField.classList.add('valid');
                                }
                            }
                        } catch (e) {
                            console.error("Error parsing JSON response:", e);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error("AJAX request failed:", error);
                    }
                });
            } else {
                fetch('./check_email.php?email=' + encodeURIComponent(email))
                    .then(response => response.json())
                    .then(data => {
                        if (data.exists) {
                            showError('add_email_error', 'This email is already taken!');
                            adminEmailExists = true;
                            emailField.classList.add('error');
                            emailField.classList.remove('valid');
                        } else {
                            clearError('add_email_error');
                            adminEmailExists = false;
                            emailField.classList.remove('error');
                            if (email.match(/^[a-zA-Z0-9._-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,6}$/)) {
                                emailField.classList.add('valid');
                            }
                        }
                    })
                    .catch(error => {
                        console.error("Fetch request failed:", error);
                    });
            }
        } else {
            clearError('add_email_error');
            adminEmailExists = false;
            emailField.classList.remove('error', 'valid');
        }
    });
}

// AJAX Check for Username availability
function checkUsernameAvailability() {
    var usernameField = document.getElementById("add_username");
    if (!usernameField) return;
    
    usernameField.addEventListener('keyup', function() {
        var username = this.value.trim();
        
        if (username.length > 0) {
            if (typeof $ !== 'undefined') {
                $.ajax({
                    url: './check_username.php',
                    type: 'GET',
                    data: { username: username },
                    success: function(response) {
                        try {
                            var data = typeof response === 'string' ? JSON.parse(response) : response;
                            if (data.exists) {
                                showError('add_username_error', 'This username is already taken!');
                                adminUsernameExists = true;
                                usernameField.classList.add('error');
                                usernameField.classList.remove('valid');
                            } else {
                                clearError('add_username_error');
                                adminUsernameExists = false;
                                usernameField.classList.remove('error');
                                if (username.length >= 4) {
                                    usernameField.classList.add('valid');
                                }
                            }
                        } catch (e) {
                            console.error("Error parsing JSON response:", e);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error("AJAX request failed:", error);
                    }
                });
            } else {
                fetch('./check_username.php?username=' + encodeURIComponent(username))
                    .then(response => response.json())
                    .then(data => {
                        if (data.exists) {
                            showError('add_username_error', 'This username is already taken!');
                            adminUsernameExists = true;
                            usernameField.classList.add('error');
                            usernameField.classList.remove('valid');
                        } else {
                            clearError('add_username_error');
                            adminUsernameExists = false;
                            usernameField.classList.remove('error');
                            if (username.length >= 4) {
                                usernameField.classList.add('valid');
                            }
                        }
                    })
                    .catch(error => {
                        console.error("Fetch request failed:", error);
                    });
            }
        } else {
            clearError('add_username_error');
            adminUsernameExists = false;
            usernameField.classList.remove('error', 'valid');
        }
    });
}

// Main form validation function
function validateAdminForm() {
    // Check AJAX validation flags FIRST
    if (adminIdNumberExists) {
        showError("add_idnumber_error", "This ID number is already taken!");
        return false;
    }
    if (adminEmailExists) {
        showError("add_email_error", "This email is already taken!");
        return false;
    }
    if (adminUsernameExists) {
        showError("add_username_error", "This username is already taken!");
        return false;
    }
    
    var idnumber = document.getElementById("add_idnumber")?.value || "";
    var firstname = document.getElementById("add_firstname")?.value || "";
    var lastname = document.getElementById("add_lastname")?.value || "";
    var middlename = document.getElementById("add_middlename")?.value || "";
    var suffix = document.getElementById("add_suffix")?.value || "";
    var sex = document.getElementById("add_sex")?.value || "";
    var birthdate = document.getElementById("add_birthdate")?.value || "";
    var age = document.getElementById("add_age")?.value || "";
    var street = document.getElementById("add_street")?.value || "";
    var barangay = document.getElementById("add_barangay")?.value || "";
    var municipality = document.getElementById("add_municipality")?.value || "";
    var province = document.getElementById("add_province")?.value || "";
    var country = document.getElementById("add_country")?.value || "";
    var postal = document.getElementById("add_postal")?.value || "";
    var email = document.getElementById("add_email")?.value || "";
    var username = document.getElementById("add_username")?.value || "";

    // Basic required field checks without blocking legitimate admin creation
    if (idnumber === "") { showError("add_idnumber_error", "ID Number is required!"); return false; }
    clearError("add_idnumber_error");

    if (firstname === "") { showError("add_firstname_error", "First name is required!"); return false; }
    if (firstname.length < 2) { showError("add_firstname_error", "First name is too short!"); return false; }
    clearError("add_firstname_error");

    if (lastname === "") { showError("add_lastname_error", "Last name is required!"); return false; }
    if (lastname.length < 2) { showError("add_lastname_error", "Last name is too short!"); return false; }
    clearError("add_lastname_error");

    if (sex === "") { showError("add_sex_error", "Please select sex!"); return false; }
    clearError("add_sex_error");

    if (birthdate === "") { showError("add_birthdate_error", "Birthdate is required!"); return false; }
    clearError("add_birthdate_error");

    if (age === "" || Number(age) < 0) { showError("add_age_error", "Age is required!"); return false; }
    clearError("add_age_error");

    if (street === "") { showError("add_street_error", "Purok is required!"); return false; }
    clearError("add_street_error");

    if (barangay === "") { showError("add_barangay_error", "Barangay is required!"); return false; }
    clearError("add_barangay_error");

    if (municipality === "") { showError("add_municipality_error", "Municipality/City is required!"); return false; }
    clearError("add_municipality_error");

    if (province === "") { showError("add_province_error", "Province is required!"); return false; }
    clearError("add_province_error");

    if (country === "") { showError("add_country_error", "Country is required!"); return false; }
    clearError("add_country_error");

    if (postal === "") { showError("add_postal_error", "Zip code is required!"); return false; }
    clearError("add_postal_error");

    if (email === "") { showError("add_email_error", "Email is required!"); return false; }
    clearError("add_email_error");

    if (username === "") { showError("add_username_error", "Username is required!"); return false; }
    clearError("add_username_error");

    return true;
}

// Helper functions
function showError(elementId, message) {
    var errorElement = document.getElementById(elementId);
    if (errorElement) {
        errorElement.innerHTML = message;
        errorElement.style.color = "red";
        errorElement.style.fontSize = "12px";
        errorElement.style.display = "block";
    }
}

function clearError(elementId) {
    var errorElement = document.getElementById(elementId);
    if (errorElement) {
        errorElement.innerHTML = "";
        errorElement.style.display = "none";
    }
}

// Auto-capitalization for text fields
function setupAutoCapitalization(fieldId) {
    var field = document.getElementById(fieldId);
    if (field) {
        field.addEventListener('input', function() {
            let input = this.value;
            let cleanedInput = input.replace(/\s{2,}/g, ' ');
            let formattedInput = cleanedInput
                .split(' ')
                .map(word => word.charAt(0).toUpperCase() + word.slice(1).toLowerCase())
                .join(' ');
            this.value = formattedInput;
        });
    }
}

// Remove leading spaces and special characters
function setupNoLeadingSpaces(fieldId) {
    var field = document.getElementById(fieldId);
    if (field) {
        field.addEventListener('input', function() {
            this.value = this.value.replace(/^\s+/g, '');
            this.value = this.value.replace(/^[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]+/, '');
        });
    }
}

// Prevent consecutive duplicate characters (more than 2)
function setupConsecutiveCharCheck(fieldId, errorId) {
    var field = document.getElementById(fieldId);
    if (field) {
        field.addEventListener('input', function(event) {
            let inputValue = this.value;
            let lastChar = '';
            let count = 0;
            
            for (let i = 0; i < inputValue.length; i++) {
                const currentChar = inputValue[i].toLowerCase();
                if (currentChar === lastChar) {
                    count++;
                    if (count === 3) {
                        inputValue = inputValue.slice(0, i) + inputValue.slice(i + 1);
                        this.value = inputValue;
                        return;
                    }
                } else {
                    lastChar = currentChar;
                    count = 1;
                }
            }
        });
    }
}

// Letters only validation
function setupLettersOnly(fieldId, errorId) {
    var field = document.getElementById(fieldId);
    if (field) {
        field.addEventListener('input', function() {
            var regex = /[^a-zA-Z\s]/g;
            if (regex.test(this.value)) {
                showError(errorId, 'Only letters and spaces allowed!');
                this.value = this.value.replace(regex, '');
            } else {
                clearError(errorId);
            }
        });
    }
}

// Numbers only validation
function setupNumbersOnly(fieldId, errorId) {
    var field = document.getElementById(fieldId);
    if (field) {
        field.addEventListener('input', function() {
            var regex = /\D/;
            if (regex.test(this.value)) {
                showError(errorId, 'Numbers only!');
                this.value = this.value.replace(regex, '');
            } else {
                clearError(errorId);
            }
        });
    }
}

// ID Number formatting
function setupIDNumberFormat(fieldId) {
    var field = document.getElementById(fieldId);
    if (field) {
        field.addEventListener('input', function() {
            var input = this.value;
            this.value = input.replace(/[^0-9-]/g, '');
            
            if (this.value.length === 5 && this.value[4] !== '-') {
                this.value = this.value.slice(0, 4) + '-' + this.value.slice(4);
            }
            
            if (this.value.length > 9) {
                this.value = this.value.slice(0, 9);
            }
        });
    }
}

// Username validation (lowercase, alphanumeric, dots, underscores)
function setupUsernameValidation(fieldId, errorId) {
    var field = document.getElementById(fieldId);
    if (field) {
        field.addEventListener('input', function() {
            var usernameInput = this.value.toLowerCase();
            var regex = /[^a-z0-9._]/g;
            
            if (/^\d/.test(usernameInput)) {
                showError(errorId, 'Username cannot start with a number!');
                this.value = usernameInput.slice(1);
            } else if (/^[._]/.test(usernameInput)) {
                showError(errorId, 'Username cannot start with a special character!');
                this.value = usernameInput.slice(1);
            } else if (regex.test(usernameInput)) {
                showError(errorId, 'Only letters, numbers, dots, and underscores allowed!');
                this.value = this.value.replace(regex, '');
            } else {
                clearError(errorId);
                this.value = usernameInput;
            }
        });
    }
}

// Email validation (lowercase)
function setupEmailValidation(fieldId, errorId) {
    var field = document.getElementById(fieldId);
    if (field) {
        field.addEventListener('input', function() {
            this.value = this.value.toLowerCase();
            var emailInput = this.value;
            var emailRegex = /^[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}$/;
            
            if (emailInput && !emailRegex.test(emailInput)) {
                showError(errorId, 'Please enter a valid email address!');
                var regex = /[^a-z0-9@_.-]/g;
                this.value = this.value.replace(regex, '');
            } else {
                clearError(errorId);
            }
        });
    }
}

// Password strength validation
function setupPasswordStrength(fieldId, errorId) {
    var field = document.getElementById(fieldId);
    if (field) {
        field.addEventListener('input', function() {
            var password = this.value;
            
            // Remove spaces
            if (/\s/.test(password)) {
                this.value = this.value.replace(/\s/g, '');
                return;
            }
            
            var hasUppercase = /[A-Z]/.test(password);
            var hasNumber = /\d/.test(password);
            var hasSpecialChar = /\W/.test(password);
            var isLengthValid = password.length >= 8;
            
            var unmetCriteria = [];
            if (!isLengthValid) unmetCriteria.push("8+ characters");
            if (!hasUppercase) unmetCriteria.push("1 uppercase");
            if (!hasNumber) unmetCriteria.push("1 number");
            if (!hasSpecialChar) unmetCriteria.push("1 special character");
            
            if (unmetCriteria.length > 0 && password.length > 0) {
                showError(errorId, "Password needs: " + unmetCriteria.join(", "));
            } else {
                clearError(errorId);
            }
        });
    }
}

// Suffix validation
function setupSuffixValidation(fieldId, errorId) {
    var field = document.getElementById(fieldId);
    if (field) {
        field.addEventListener('blur', function() {
            var suffixValue = this.value.trim().toUpperCase();
            
            if (suffixValue === "SR") {
                suffixValue = "Sr.";
            } else if (suffixValue === "JR") {
                suffixValue = "Jr.";
            }
            
            var validSuffixRegex = /^(M{0,3})(CM|CD|D?C{0,3})(XC|XL|L?X{0,3})(IX|IV|V?I{0,3})$|^Jr\.$|^Sr\.$/;
            
            if (suffixValue && !validSuffixRegex.test(suffixValue)) {
                showError(errorId, "Invalid suffix (e.g., Jr., Sr., I, II, III)");
                this.value = "";
            } else {
                clearError(errorId);
                this.value = suffixValue;
            }
        });
    }
}

// Purok/Street validation (letters, numbers, spaces, hyphens)
function setupStreetValidation(fieldId, errorId) {
    var field = document.getElementById(fieldId);
    if (field) {
        field.addEventListener('input', function() {
            var input = this.value;
            var regex = /[^a-zA-Z0-9\s-]/g;
            
            if (/^\d/.test(input)) {
                showError(errorId, 'Cannot start with a number!');
                this.value = this.value.replace(/^\d/, '');
            } else if (/\d[a-zA-Z]/.test(input)) {
                showError(errorId, 'Numbers should not appear before letters!');
                this.value = this.value.replace(/\d[a-zA-Z]/, '');
            } else if (regex.test(input)) {
                showError(errorId, 'Only letters, numbers, spaces, and hyphens allowed!');
                this.value = this.value.replace(regex, '');
            } else {
                clearError(errorId);
            }
        });
    }
}

// Initialize all validations when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    // Initialize AJAX validations
    checkIdNumberAvailability();
    checkEmailAvailability();
    checkUsernameAvailability();
    
    // Auto-capitalization
    setupAutoCapitalization('add_firstname');
    setupAutoCapitalization('add_lastname');
    setupAutoCapitalization('add_middlename');
    setupAutoCapitalization('add_street');
    setupAutoCapitalization('add_barangay');
    setupAutoCapitalization('add_municipality');
    setupAutoCapitalization('add_province');
    setupAutoCapitalization('add_country');
    
    // No leading spaces
    setupNoLeadingSpaces('add_firstname');
    setupNoLeadingSpaces('add_lastname');
    setupNoLeadingSpaces('add_middlename');
    setupNoLeadingSpaces('add_suffix');
    setupNoLeadingSpaces('add_street');
    setupNoLeadingSpaces('add_barangay');
    setupNoLeadingSpaces('add_municipality');
    setupNoLeadingSpaces('add_province');
    setupNoLeadingSpaces('add_country');
    setupNoLeadingSpaces('add_postal');
    setupNoLeadingSpaces('add_password');
    setupNoLeadingSpaces('add_confirm_password');
    
    // Consecutive character check
    setupConsecutiveCharCheck('add_firstname', 'add_firstname_error');
    setupConsecutiveCharCheck('add_lastname', 'add_lastname_error');
    setupConsecutiveCharCheck('add_middlename', 'add_middlename_error');
    setupConsecutiveCharCheck('add_street', 'add_street_error');
    setupConsecutiveCharCheck('add_barangay', 'add_barangay_error');
    setupConsecutiveCharCheck('add_municipality', 'add_municipality_error');
    setupConsecutiveCharCheck('add_province', 'add_province_error');
    setupConsecutiveCharCheck('add_country', 'add_country_error');
    setupConsecutiveCharCheck('add_email', 'add_email_error');
    
    // Letters only
    setupLettersOnly('add_firstname', 'add_firstname_error');
    setupLettersOnly('add_lastname', 'add_lastname_error');
    setupLettersOnly('add_middlename', 'add_middlename_error');
    setupLettersOnly('add_municipality', 'add_municipality_error');
    setupLettersOnly('add_province', 'add_province_error');
    setupLettersOnly('add_country', 'add_country_error');
    
    // Numbers only
    setupNumbersOnly('add_postal', 'add_postal_error');
    
    // Special validations
    setupIDNumberFormat('add_idnumber');
    setupUsernameValidation('add_username', 'add_username_error');
    setupEmailValidation('add_email', 'add_email_error');
    setupPasswordStrength('add_password', 'add_password_error');
    setupSuffixValidation('add_suffix', 'add_suffix_error');
    setupStreetValidation('add_street', 'add_street_error');
    
    // Default selection to keep the admin creation flow working
    const defaultRole = document.getElementById('role_admin');
    if (defaultRole) defaultRole.checked = true;

    const defaultStatus = document.querySelector('select[name="status"]');
    if (defaultStatus && !defaultStatus.value) defaultStatus.value = 'active';

    // Form submit validation
    var form = document.getElementById('addAdminForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            if (!validateAdminForm()) {
                e.preventDefault();
                alert('Please fill in the required fields before submitting.');
                return false;
            }
        });
    }
});
