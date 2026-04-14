// add hovered class on CLICK (not hover) so highlight doesn't stick after unhover
let list = document.querySelectorAll('.navigation li');
function activeLink(){
    list.forEach((item) => item.classList.remove('hovered'));
    this.classList.add('hovered');
}
list.forEach((item) => item.addEventListener('click', activeLink));

// MenuToggle
let toggle = document.querySelector('.toggle');
let navigation = document.querySelector('.navigation');
let main = document.querySelector('.main');

if (toggle) {
  toggle.onclick = function(){
      navigation?.classList.toggle('active');
      main?.classList.toggle('active');
  };
}

// Ensure chart registry exists for analytics scripts
window.dashboardCharts = window.dashboardCharts || {};
