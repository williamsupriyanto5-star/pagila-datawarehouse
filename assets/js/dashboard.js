// Revenue Chart

fetch("../api/dashboard_kpi.php")

    .then(response => response.json())

    .then(data => {

        document.getElementById("revenueCard").innerHTML = "Rp " + Number(data.revenue).toLocaleString();

        document.getElementById("rentalCard").innerHTML = data.rental;

        document.getElementById("customerCard").innerHTML = data.customer;

        document.getElementById("avgCard").innerHTML = "Rp " + Number(data.avg_transaction).toLocaleString();

    });

fetch("../api/revenue_month.php")

    .then(response => response.json())

    .then(data => {

        const label = data.map(item => item.bulan);

        const revenue = data.map(item => parseFloat(item.revenue));

        new Chart(document.getElementById("revenueChart"), {

            type: "line",

            data: {

                labels: label,

                datasets: [{

                    label: "Revenue",

                    data: revenue,

                    fill: true,

                    borderWidth: 3,

                    tension: .4

                }]

            }

        });

    });

// Store Chart

fetch("../api/revenue_store.php")

    .then(response => response.json())

    .then(data => {

        const label = data.map(item => "Store " + item.store_key);

        const revenue = data.map(item => parseFloat(item.revenue));

        new Chart(document.getElementById("storeChart"), {

            type: "doughnut",

            data: {

                labels: label,

                datasets: [{

                    data: revenue

                }]

            }

        });

    });

fetch("../api/top_film.php")

    .then(response => response.json())

    .then(data => {

        const label = data.map(item => item.title);

        const value = data.map(item => parseFloat(item.revenue));

        new Chart(document.getElementById("filmChart"), {

            type: "bar",

            data: {

                labels: label,

                datasets: [{

                    label: "Revenue",

                    data: value

                }]

            },

            options: {

                indexAxis: 'y'

            }

        });

    });

fetch("../api/revenue_month.php")
    .then(res => res.json())
    .then(data => {

        let label = data.map(item => item.month_name);

        let revenue = data.map(item => parseFloat(item.revenue));

        new Chart(document.getElementById("revenueChart"), {

            type: "line",

            data: {

                labels: label,

                datasets: [{

                    label: "Revenue",

                    data: revenue,

                    fill: true,

                    borderWidth: 3,

                    tension: .4

                }]

            }

        });

    });

fetch("../api/store_revenue.php")
    .then(res => res.json())
    .then(data => {

        let label = data.map(item => "Store " + item.store_key);

        let revenue = data.map(item => parseFloat(item.revenue));

        new Chart(document.getElementById("storeChart"), {

            type: "doughnut",

            data: {

                labels: label,

                datasets: [{

                    data: revenue

                }]

            }

        });

    });

fetch("../api/topfilm.php")
    .then(res => res.json())
    .then(data => {

        let label = data.map(item => item.title);

        let revenue = data.map(item => parseFloat(item.revenue));

        new Chart(document.getElementById("filmChart"), {

            type: "bar",

            data: {

                labels: label,

                datasets: [{

                    label: "Revenue",

                    data: revenue

                }]

            },

            options: {

                indexAxis: 'y'

            }

        });

    });

fetch("../api/customer_segment.php")
    .then(res => res.json())
    .then(data => {

        let label = data.map(item => item.risk);

        let total = data.map(item => parseInt(item.total));

        new Chart(document.getElementById("customerChart"), {

            type: "pie",

            data: {

                labels: label,

                datasets: [{

                    data: total

                }]

            }

        });

    });

fetch("../api/top_customer.php")
    .then(res => res.json())
    .then(data => {

        let label = data.map(item => item.city ? ("City " + item.city) : ("Customer " + item.customer_key));

        let total = data.map(item => parseFloat(item.customer_lifetime_value));

        new Chart(document.getElementById("topCustomerChart"), {

            type: "bar",

            data: {

                labels: label,

                datasets: [{

                    label: "Customer Lifetime Value",

                    data: total

                }]

            }

        });

    });