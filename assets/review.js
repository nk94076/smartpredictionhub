
        document.addEventListener("DOMContentLoaded", () => {

            /*=====================================
              Elements
            =====================================*/

            const body = document.body;

            const header = document.getElementById("siteHeader");

            const mobileMenu = document.getElementById("mobileMenu");

            const mobileOverlay = document.getElementById("mobileOverlay");

            const openBtn = document.getElementById("mobileMenuBtn");

            const closeBtn = document.getElementById("closeMobileMenu");

            const accordionButtons = document.querySelectorAll(".mobile-trigger");



            /*=====================================
              Mobile Menu
            =====================================*/

            function openMobileMenu() {

                mobileMenu.classList.remove("translate-x-full");

                mobileOverlay.classList.remove("opacity-0", "invisible");

                body.classList.add("overflow-hidden");

            }

            function closeMobileMenu() {

                mobileMenu.classList.add("translate-x-full");

                mobileOverlay.classList.add("opacity-0", "invisible");

                body.classList.remove("overflow-hidden");

            }

            openBtn.addEventListener("click", openMobileMenu);

            closeBtn.addEventListener("click", closeMobileMenu);

            mobileOverlay.addEventListener("click", closeMobileMenu);



            /*=====================================
              ESC Close
            =====================================*/

            document.addEventListener("keydown", (e) => {

                if (e.key === "Escape") {

                    closeMobileMenu();

                }

            });



            /*=====================================
              Mobile Accordion
              (Only One Open)
            =====================================*/

            accordionButtons.forEach(button => {

                button.addEventListener("click", () => {

                    const parent = button.parentElement;

                    const content = parent.querySelector(".mobile-content");

                    const icon = button.querySelector("i");

                    const isOpen = !content.classList.contains("hidden");

                    document.querySelectorAll(".mobile-content").forEach(item => {

                        item.classList.add("hidden");

                    });

                    document.querySelectorAll(".mobile-trigger i").forEach(i => {

                        i.classList.remove("rotate-180");

                    });

                    if (!isOpen) {

                        content.classList.remove("hidden");

                        icon.classList.add("rotate-180");

                    }

                });

            });



            /*=====================================
              Sticky Header
            =====================================*/

            let lastScroll = 0;

            window.addEventListener("scroll", () => {

                const currentScroll = window.pageYOffset;

                if (currentScroll <= 0) {

                    header.classList.remove(
                        "shadow-2xl",
                        "bg-[#09071B]/95",
                        "backdrop-blur-xl"
                    );

                    header.style.transform = "translateY(0)";

                    return;

                }

                header.classList.add(
                    "shadow-2xl",
                    "bg-[#09071B]/95",
                    "backdrop-blur-xl"
                );

                if (
                    currentScroll > lastScroll &&
                    currentScroll > 120
                ) {

                    header.style.transform = "translateY(-100%)";

                } else {

                    header.style.transform = "translateY(0)";

                }

                lastScroll = currentScroll;

            });



            /*=====================================
              Active Navigation
            =====================================*/

            const navButtons = document.querySelectorAll("nav ul li button");

            navButtons.forEach(btn => {

                btn.addEventListener("click", () => {

                    navButtons.forEach(item => {

                        item.classList.remove("text-primary");

                        item.classList.add("text-white");

                    });

                    btn.classList.remove("text-white");

                    btn.classList.add("text-primary");

                });

            });



            /*=====================================
              Desktop Dropdown
              Close When Clicking Outside
            =====================================*/

            document.addEventListener("click", (e) => {

                if (!e.target.closest(".group")) {

                    document.querySelectorAll(".group").forEach(item => {

                        const menu = item.querySelector("div.absolute");

                        if (!menu) return;

                        menu.classList.add(
                            "opacity-0",
                            "invisible",
                            "translate-y-4"
                        );

                    });

                }

            });



            /*=====================================
              Resize Fix
            =====================================*/

            window.addEventListener("resize", () => {

                if (window.innerWidth >= 1280) {

                    closeMobileMenu();

                }

            });

        });


document.addEventListener("DOMContentLoaded", function () {

    const stars = document.querySelectorAll(".review-star");

    const ratingInput =
        document.getElementById("reviewRating");

    const ratingLabel =
        document.querySelector(".rating-label");

    const ratingText = {
        1: "Poor",
        2: "Fair",
        3: "Good",
        4: "Very good",
        5: "Excellent"
    };


    stars.forEach((star) => {

        star.addEventListener("click", function () {

            const selected =
                Number(this.dataset.rating);

            ratingInput.value =
                selected;


            stars.forEach((item) => {

                const value =
                    Number(item.dataset.rating);

                item.classList.toggle(
                    "active",
                    value <= selected
                );

            });


            ratingLabel.textContent =
                selected +
                "/5 — " +
                ratingText[selected];

        });

    });


    /* Character counter */

    const reviewBox =
        document.getElementById("reviewMessage");

    const counter =
        document.getElementById(
            "reviewCharacterCount"
        );


    reviewBox.addEventListener("input", function () {

        counter.textContent =
            this.value.length +
            " / 800";

    });


    /* Demo form submit */

    const form =
        document.getElementById(
            "platformReviewForm"
        );


    form.addEventListener("submit", function (e) {

        if (!ratingInput.value) {

            e.preventDefault();

            ratingLabel.textContent =
                "Please select a rating";

            ratingLabel.style.color =
                "#EF4444";

            return;

        }

        /*
        Remove e.preventDefault() below
        when connecting this form
        to PHP / API / database.
        */

        e.preventDefault();

        alert(
            "Thanks! Your review has been submitted for moderation."
        );

    });

});

document.addEventListener("DOMContentLoaded", function () {

    new Swiper(".readerReviewsSwiper", {

        slidesPerView: 1.12,

        spaceBetween: 16,

        speed: 550,

        grabCursor: true,

        watchOverflow: true,

        pagination: {

            el:
                ".readerReviewsSwiper .swiper-pagination",

            clickable:
                true

        },

        navigation: {

            nextEl:
                ".reader-review-next",

            prevEl:
                ".reader-review-prev"

        },

        breakpoints: {

            480: {

                slidesPerView:
                    1.3,

                spaceBetween:
                    16

            },


            640: {

                slidesPerView:
                    1.7,

                spaceBetween:
                    18

            },


            768: {

                slidesPerView:
                    2,

                spaceBetween:
                    20

            },


            1024: {

                slidesPerView:
                    3,

                spaceBetween:
                    20

            }

        }

    });

});
