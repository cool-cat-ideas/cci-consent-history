# Hooks reference

The module registers three native PrestaShop hooks.

## `actionCustomerAccountAdd`

Use: record the account privacy wording accepted while a customer account is created.

Parameters:

- `newCustomer` (`Customer`): the customer created by PrestaShop.

Return: none.

Side effect: adds an acceptance linked to the current immutable wording version when the `psgdpr` account creation consent is active.

## `actionFrontControllerSetMedia`

Use: load the renewal dialog CSS and JavaScript only for a logged-in customer who needs to accept changed wording.

Parameters: standard hook context; no custom keys are required.

Return: none.

Side effect: registers `views/css/front.css` and `views/js/front.js`.

## `displayFooter`

Use: render the blocking renewal dialog when the current customer has not accepted the current wording version.

Parameters: standard hook context; no custom keys are required.

Return: HTML string or an empty string.

Side effect: none until the customer submits the form. Submission is handled by the module front controller `accept`.
