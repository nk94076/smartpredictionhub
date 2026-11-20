

document.addEventListener("DOMContentLoaded", () => {

    new Swiper(".platformSwiper", {

        slidesPerView: 1.15,

        spaceBetween: 18,

        centeredSlides: false,

        speed: 600,

        grabCursor: true,

        pagination: {

            el: ".platformSwiper .swiper-pagination",

            clickable: true,

        },

        breakpoints: {

            480: {

                slidesPerView: 1.25

            },

            640: {

                slidesPerView: 1.5

            },

            768: {

                slidesPerView: 1.8

            }

        }

    });

});





document.addEventListener("DOMContentLoaded", () => {

    new Swiper(".trendingMarketsSwiper", {

        slidesPerView: 1.15,

        spaceBetween: 18,

        speed: 600,

        grabCursor: true,

        pagination: {

            el: ".trendingMarketsSwiper .swiper-pagination",

            clickable: true,

        },

        breakpoints: {

            480: {

                slidesPerView: 1.25

            },

            640: {

                slidesPerView: 1.6

            },

            768: {

                slidesPerView: 2

            }

        }

    });

});





document.addEventListener("DOMContentLoaded", function () {

    new Swiper(".latestNewsSwiper", {

        slidesPerView: 1.1,

        spaceBetween: 18,

        speed: 600,

        grabCursor: true,

        pagination: {

            el: ".latestNewsSwiper .swiper-pagination",

            clickable: true,

        },

        breakpoints: {

            480: {

                slidesPerView: 1.2

            },

            640: {

                slidesPerView: 1.45

            },

            768: {

                slidesPerView: 2

            }

        }

    });

});





document.addEventListener("DOMContentLoaded", () => {

    const btn = document.getElementById("backToTop");

    if (btn) {

        btn.addEventListener("click", () => {

            window.scrollTo({

                top: 0,

                behavior: "smooth"

            });

        });

    }

});


