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
    .diagram-text {
        font-family: monospace;
        background-color: #1E293B;
        color: #38BDF8;
        padding: 15px;
        border-radius: 8px;
        white-space: pre;
        overflow-x: auto;
        line-height: 1.4;
    }
    </style>
""", unsafe_allow_html=True)

# Generate mock data
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
    "💡 Teori & Diagram OLTP vs OLAP"
])

st.sidebar.markdown("---")
selected_store = st.sidebar.multiselect("Pilih Toko / Cabang", options=fact_sales['store_id'].unique(), default=fact_sales['store_id'].unique())
filtered_sales = fact_sales[fact_sales['store_id'].isin(selected_store)]

# --- PAGE 1, 2, 3 ---
if page == "🎯 Ringkasan Eksekutif":
    st.markdown('<div class="main-title">🎯 Executive BI Dashboard - Pagila Data Warehouse</div>', unsafe_allow_html=True)
    st.markdown('<div class="subtitle">Analisis berbasis data dari Fact Tables hasil transformasi OLTP ke Star Schema</div>', unsafe_allow_html=True)
    col1, col2, col3, col4 = st.columns(4)
    col1.metric("💰 Total Pendapatan", f"${filtered_sales['amount'].sum():,.2f}")
    col2.metric("🛒 Total Transaksi", f"{len(filtered_sales):,}")
    col3.metric("⏱️ Rata-rata Durasi Sewa", f"{fact_rental['rental_duration_days'].mean():.1f} Hari")
    col4.metric("⚠️ Rasio Keterlambatan", f"{(fact_rental['is_late'].sum() / len(fact_rental)) * 100:.1f}%")
    
    st.markdown("### 📊 Tren Utama Bisnis")
    c1, c2 = st.columns(2)
    with c1:
        filtered_sales['Bulan'] = filtered_sales['date'].dt.to_period('M').astype(str)
        monthly_rev = filtered_sales.groupby('Bulan')['amount'].sum().reset_index()
        st.plotly_chart(px.line(monthly_rev, x='Bulan', y='amount', template="plotly_white", markers=True), use_container_width=True)
    with c2:
        cat_rev = filtered_sales.groupby('category')['amount'].sum().reset_index().sort_values('amount', ascending=False).head(10)
        st.plotly_chart(px.bar(cat_rev, x='amount', y='category', orientation='h', template="plotly_white"), use_container_width=True)

elif page == "📈 Analisis Penjualan (Fact Sales)":
    st.markdown('<div class="main-title">📈 Analisis Penjualan & Pendapatan</div>', unsafe_allow_html=True)
    st.dataframe(filtered_sales.head(100), use_container_width=True)

elif page == "🎬 Kinerja Rental (Fact Rental)":
    st.markdown('<div class="main-title">🎬 Kinerja Operasional Penyewaan</div>', unsafe_allow_html=True)
    st.plotly_chart(px.histogram(fact_rental, x='rental_duration_days', nbins=7, template="plotly_white"), use_container_width=True)

# --- PAGE 4: THEORETICAL WITH DIAGRAM ---
elif page == "💡 Teori & Diagram OLTP vs OLAP":
    st.markdown('<div class="main-title">💡 Pembahasan Teori & Arsitektur Data</div>', unsafe_allow_html=True)
    st.markdown('<div class="subtitle">Visualisasi Alur Kerja Sistem Data Warehouse Pagila</div>', unsafe_allow_html=True)
    
    # DIAGRAM 1: ARSITEKTUR PIPELINE DATA
    st.markdown("### 🗺️ 1. Diagram Alur Data (Pipeline ETL)")
    diagram_etl = """
[ DATABASE SOURCE: OLTP ] ──► (Proses Sinkronisasi) ──► [ STAGING AREA (SQL) ]
  - Aplikasi Kasir Toko                                   - tabel: staging_customer
  - Input Sewa Real-time                                  - tabel: staging_payment
           │                                                         │
           ▼                                                         ▼
[ DATA VISUALISASI / BI ] ◄── (Query Agregat) ◄──── [ DATA WAREHOUSE: OLAP ]
  - Dashboard Streamlit Ini                               - Star Schema (Tabel Fakta & Dimensi)
    """
    st.markdown(f'<div class="diagram-text">{diagram_etl}</div>', unsafe_allow_html=True)
    
    # DIAGRAM 2: STAR SCHEMA
    st.markdown("### 📐 2. Diagram Skema Bintang (Star Schema DWH)")
    diagram_star = """
      [ dim_customer ]               [ dim_store ]
             │                              │
             ▼                              ▼
       ┌──────────────────────────────────────────┐
       │                FACT_SALES                │
 ─────►│  (Kolom Metrik: amount, sales_key, dll)  │◄───── [ dim_date ]
       └──────────────────────────────────────────┘
             ▲                              ▲
             │                              │
         [ dim_film ]               [ dim_geography ]
    """
    st.markdown(f'<div class="diagram-text">{diagram_star}</div>', unsafe_allow_html=True)
    
    # Perbandingan Tabel
    st.markdown("### 📊 Tabel Perbandingan Karakteristik")
    oltp_vs_olap_data = {
        "Karakteristik": ["Fokus Utama", "Desain Tabel", "Operasi Dominan", "Contoh di File SQL Anda"],
        "OLTP": ["Operasional & Pencatatan Transaksi", "Normalisasi (3NF) - Tanpa Duplikasi", "Insert, Update, Delete", "Tabel mentah awal 'staging_payment'"],
        "OLAP": ["Analisis & Pelaporan Tren Bisnis", "Denormalisasi (Star Schema)", "Select / Read-Only", "Tabel DWH akhir 'fact_sales'"]
    }
    st.table(pd.DataFrame(oltp_vs_olap_data))

st.markdown("---")
st.info("ℹ️ Aplikasi Dashboard ini disimulasikan sesuai struktur data model **Star Schema** yang didefinisikan pada file `pagiladwnew.sql`.")
