

//=>() add()
function add(event) {
    const total = Number(event.a) + Number(event.b);
    document.querySelector('#result').textContent = formatNumber(total);
}
//=>() end

//=>() subtract()
function subtract(event) {
    const total = Number(event.a) - Number(event.b);
    document.querySelector('#result').textContent = formatNumber(total);
}
//=>() end

//=>() multiply()
function multiply(event) {
    const total = Number(event.a) * Number(event.b);
    document.querySelector('#result').textContent = formatNumber(total);
}
//=>() end

