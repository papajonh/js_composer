//=>() withJquery()
function withJquery(event) {
    const total = Number(event.a) * 2;
    $('#result').text(formatNumber(total));
}
//=>() end