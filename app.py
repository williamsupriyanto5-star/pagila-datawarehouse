import streamlit as st
import pandas as pd
import numpy as np
import plotly.express as px

# Set page layout to wide
st.set_page_config(
    page_title="Pagila Data Warehouse Executive Dashboard",
    page_icon="🎬",
    layout="wide",
    initial_sidebar_state="expanded"
)

# Custom Styling
st.markdown("""
    <style>
    .main-title {
        font-size: 32px;
        font-weight: bold;
        color: #1E3A8A;
        margin-bottom: 5px;
    }
    .subtitle {
        font-size: 16px;
        color: #6B7280;
        margin-bottom: 25px;
    }
    </style>
""", unsafe_allow_html=True)

# PENGGANTI POSTGRESQL LOCALHOST: Generate mock data dari Star Schema Pagila
@st.cache_data
def generate_mock_dwh_data():
    categories = ['Action', 'Animation', 'Children', 'Classics', 'Comedy', 'Documentary', 'Drama', 'Family', 'Foreign', 'Games', 'Horror', 'Music', 'New', 'Sci-Fi', 'Sports', 'Travel']
    np.random.seed(42)
    n_sales = 1000
    dates = pd.date_range(start="2025-01-01", end="2025-12-31", freq="D")
    
    fact_sales = pd.DataFrame({
        'sales_key': range(1, n_sales + 1),
        'date': np.random.choice(dates, n_sales),
        'amount': np.random.exponential(scale=5.0, size=n_sales) + 2.5,
        'category': np.random.choice(categories, n_sales),
        'store_id': np.random.choice(['Store 1 (California)', 'Store 2 (Texas)'], n_sales),
        'customer_segment': np.random.choice(['Active High', 'Regular Medium', 'Occasional Low'], n_sales, p=[0.3, 0.5, 0.2])
    })
    fact_sales['amount'] = fact_sales['amount'].round(2)
    
    n_rentals = 1200
    fact_rental = pd.DataFrame({
        'rental_key': range(1, n_rentals + 1),
        'date': np.random.choice(dates, n_rentals),
        'rental_duration_days': np.random.randint(1, 8, size=n_rentals),
        'is_late': np.random.choice([0, 1], n_rentals, p=[0.75, 0.25]),
        'category': np.random.choice(categories, n_rentals)
    })
    
    return fact_sales, fact_rental

fact_sales, fact_rental = generate_mock_dwh_data()

# Sidebar Navigation
st.sidebar.image("https://img.icons8.com/fluency/96/movie.png", width=80)
st.sidebar.title("Pagila DWH Control")
page = st.sidebar.radio("Navigasi Dashboard", [
    "🎯 Ringkasan Eksekutif", 
    "📈 Analisis Penjualan (Fact Sales)", 
    "🎬 Kinerja Rental (Fact Rental)",
    "💡 Teori: OLTP vs OLAP"
])

st.sidebar.markdown("---")
selected_store = st.sidebar.multiselect("Pilih Toko / Cabang", options=fact_sales['store_id'].unique(), default=fact_sales['store_id'].unique())
filtered_sales = fact_sales[fact_sales['store_id'].isin(selected_store)]

# --- PAGE 1: EXECUTIVE SUMMARY ---
if page == "🎯 Ringkasan Eksekutif":
    st.markdown('<div class="main-title">🎯 Executive BI Dashboard - Pagila Data Warehouse</div>', unsafe_allow_html=True)
    st.markdown('<div class="subtitle">Analisis berbasis data dari Fact Tables hasil transformasi OLTP ke Star Schema</div>', unsafe_allow_html=True)
    
    col1, col2, col3, col4 = st.columns(4)
    with col1:
        st.metric(label="💰 Total Pendapatan", value=f"${filtered_sales['amount'].sum():,.2f}")
    with col2:
        st.metric(label="🛒 Total Transaksi", value=f"{len(filtered_sales):,}")
    with col3:
        st.metric(label="⏱️ Rata-rata Durasi Sewa", value=f"{fact_rental['rental_duration_days'].mean():.1f} Hari")
    with col4:
        st.metric(label="⚠️ Rasio Keterlambatan", value=f"{(fact_rental['is_late'].sum() / len(fact_rental)) * 100:.1f}%")
        
    st.markdown("### 📊 Tren Utama Bisnis")
    c1, c2 = st.columns(2)
    with c1:
        filtered_sales['Bulan'] = filtered_sales['date'].dt.to_period('M').astype(str)
        monthly_rev = filtered_sales.groupby('Bulan')['amount'].sum().reset_index()
        st.plotly_chart(px.line(monthly_rev, x='Bulan', y='amount', labels={'amount': 'Pendapatan ($)'}, template="plotly_white", markers=True), use_container_width=True)
    with c2:
        cat_rev = filtered_sales.groupby('category')['amount'].sum().reset_index().sort_values('amount', ascending=False).head(10)
        st.plotly_chart(px.bar(cat_rev, x='amount', y='category', orientation='h', template="plotly_white"), use_container_width=True)

# --- PAGE 2: SALES ANALYSIS ---
elif page == "📈 Analisis Penjualan (Fact Sales)":
    st.markdown('<div class="main-title">📈 Analisis Penjualan & Pendapatan</div>', unsafe_allow_html=True)
    st.dataframe(filtered_sales[['sales_key', 'date', 'amount', 'category', 'store_id', 'customer_segment']].head(100), use_container_width=True)

# --- PAGE 3: RENTAL PERFORMANCE ---
elif page == "🎬 Kinerja Rental (Fact Rental)":
    st.markdown('<div class="main-title">🎬 Kinerja Operasional Penyewaan</div>', unsafe_allow_html=True)
    st.plotly_chart(px.histogram(fact_rental, x='rental_duration_days', nbins=7, template="plotly_white"), use_container_width=True)

# --- PAGE 4: THEORETICAL OLTP VS OLAP ---
elif page == "💡 Teori: OLTP vs OLAP":
    st.markdown('<div class="main-title">💡 Pembahasan Teori: OLTP vs OLAP</div>', unsafe_allow_html=True)
    oltp_vs_olap_data = {
        "Karakteristik": ["Fokus Utama", "Desain Tabel", "Operasi Dominan", "Contoh di File SQL Anda"],
        "OLTP": ["Operasional & Pencatatan Transaksi", "Normalisasi (3NF) - Tanpa Duplikasi", "Insert, Update, Delete", "Tabel mentah awal 'staging_payment'"],
        "OLAP": ["Analisis & Pelaporan Tren", "Denormalisasi (Star Schema)", "Select / Read-Only", "Tabel DWH akhir 'fact_sales'"]
    }
    st.table(pd.DataFrame(oltp_vs_olap_data))

st.markdown("---")
st.info("ℹ️ Aplikasi Dashboard ini disimulasikan sesuai struktur data model **Star Schema** yang didefinisikan pada file `pagiladwnew.sql`.")
