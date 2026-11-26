const dropdown = document.getElementById("myDropdown");
if (!dropdown) {
    // This helper is only used on pages that include the custom dropdown markup.
    // If the dropdown is absent, do nothing without breaking the page.
} else {
    const selected = dropdown.querySelector("#selectedOption");
    const options = dropdown.querySelector("#dropdownOptions");
    const arrow = dropdown.querySelector("#dropdownArrow");
    const items = dropdown.querySelectorAll(".dropdown-option");

    // hidden input to send batch_no
    const batchInput = document.getElementById("selected_batch_no");

    selected.addEventListener("click", () => {
        const isOpen = options.style.display === "block";
        options.style.display = isOpen ? "none" : "block";
        dropdown.classList.toggle("open", !isOpen);
    });

    items.forEach((item) => {
        item.addEventListener("click", () => {
            // update display text
            selected.querySelector("span").innerHTML = item.innerHTML;

            // update hidden input with selected batch_no
            const batchNo = item.getAttribute("data-batch-no");
            if (batchInput) {
                batchInput.value = batchNo;
            }

            options.style.display = "none";
            dropdown.classList.remove("open");
        });
    });

    document.addEventListener("click", function (e) {
        if (!dropdown.contains(e.target)) {
            options.style.display = "none";
            dropdown.classList.remove("open");
        }
    });
}
